<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\Busca;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\CobroService;
use App\Services\Ventas\RiesgoMoraService;
use Illuminate\Http\Request;

// Fase 69 · Lo que el CRM necesita del cliente en vivo (saldo, vencido, límite, riesgo, pendientes) y cobrar desde el CRM.
// El CRM identifica al cliente por external_id = id del ERP. Token de API de la empresa.
class CrmClientesController extends Controller
{
    public function cuenta(Contact $contact, RiesgoMoraService $riesgo)
    {
        $pend = Comprobante::where('contact_id', $contact->id)->pendientesCobro()->orderBy('fecha_vto')->limit(50)->get();
        $vencido = round((float) $pend->filter(fn($x) => $x->fecha_vto && $x->fecha_vto->lt(today()))->sum('saldo'), 2);
        $saldo = round((float) $contact->balance, 2); $limite = (float) $contact->credit_limit;
        $r = $riesgo->de($contact);
        $ultimoCobro = Cobro::where('contact_id', $contact->id)->where('estado', '!=', 'anulado')->orderByDesc('fecha')->orderByDesc('id')->first();
        return response()->json(['data' => [
            'id' => $contact->id, 'nombre' => $contact->name, 'cuit' => $contact->cuit, 'condicion_iva' => $contact->condicion_iva,
            'saldo' => $saldo, 'vencido' => $vencido, 'a_vencer' => round(max(0, $saldo - $vencido), 2), 'limite_credito' => $limite ?: null, 'disponible' => $limite > 0 ? round($limite - $saldo, 2) : null, 'excede_limite' => $limite > 0 && $saldo > $limite,
            'dias_pago' => (int) $contact->dias_pago, 'lista_precios' => (int) $contact->lista_precios, 'descuento' => (float) $contact->descuento,
            'riesgo' => ['puntaje' => $r['puntaje'] ?? null, 'nivel' => $r['nivel'] ?? null, 'motivos' => $r['motivos'] ?? []],
            'pendientes' => $pend->map(fn($x) => Busca::comp($x) + ['vencida' => (bool) ($x->fecha_vto && $x->fecha_vto->lt(today())), 'dias_vencida' => $x->fecha_vto && $x->fecha_vto->lt(today()) ? $x->fecha_vto->diffInDays(today()) : 0])->values()->all(),
            'ultimo_cobro' => $ultimoCobro ? ['id' => $ultimoCobro->id, 'numero' => $ultimoCobro->numeroFormateado(), 'fecha' => $ultimoCobro->fecha?->toDateString(), 'total' => (float) $ultimoCobro->total] : null,
            'ultimas_facturas' => Comprobante::where('contact_id', $contact->id)->ventas()->emitidos()->facturas()->orderByDesc('fecha')->orderByDesc('id')->limit(5)->get()->map(fn($x) => Busca::comp($x))->all(),
            'medios_de_cobro' => Cobro::MEDIOS, 'actualizado' => now()->toIso8601String(),
        ]]);
    }

    // El vendedor cobra desde el CRM: el recibo nace en el ERP (cuenta corriente, caja/banco y asiento), y el CRM lo ve volver por el aviso cobro.registrado.
    public function cobrar(Request $request, Contact $contact, CobroService $svc)
    {
        $d = $request->validate([
            'fecha' => 'nullable|date', 'medios' => 'required|array|min:1', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0.01',
            'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.moneda' => 'nullable|in:ARS,USD', 'medios.*.cotizacion' => 'nullable|numeric',
            'imputaciones' => 'nullable|array', 'imputaciones.*.comprobante_id' => 'required|integer', 'imputaciones.*.monto' => 'required|numeric|min:0.01',
            'descuento' => 'nullable|numeric|min:0', 'interes' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:300', 'crm_ref' => 'nullable|string|max:80',
            'vendedor_id' => 'nullable|integer', 'vendedor_email' => 'nullable|email', // Fase 74: quién cobró (para la comisión); por id del ERP o por mail
        ]);
        // Quién cobró, para la comisión: por id del ERP o por mail. Si el mail es de un usuario del ERP que todavía no es vendedor, se le crea la
        // ficha de vendedor (0 % hasta que el dueño le cargue el porcentaje): el recibo nunca queda sin cobrador por eso (hallazgo de la prueba 3.4).
        $aviso = null;
        if (! empty($d['vendedor_email']) && empty($d['vendedor_id'])) {
            $mail = strtolower(trim($d['vendedor_email']));
            $v = \App\Models\Vendedor::where('activo', true)->whereRaw('lower(email) = ?', [$mail])->first();
            $u = $v ? null : \App\Models\User::where('business_id', $contact->business_id)->whereRaw('lower(email) = ?', [$mail])->where('status', 'active')->first();
            if (! $v && $u) $v = \App\Models\Vendedor::deUsuario($u->id) ?? \App\Models\Vendedor::create(['business_id' => $contact->business_id, 'user_id' => $u->id, 'nombre' => $u->name, 'email' => $mail, 'comision_venta' => 0, 'comision_cobro' => 0, 'activo' => true]);
            if ($v) $d['vendedor_id'] = $v->id; else $aviso = "{$mail} no es usuario ni vendedor del ERP: el recibo queda sin cobrador.";
        }
        if (! empty($d['vendedor_id'])) { abort_unless(\App\Models\Vendedor::where('id', $d['vendedor_id'])->exists(), 422, 'Ese vendedor no existe en el ERP.'); $d['cobrador_id'] = (int) $d['vendedor_id']; }
        unset($d['vendedor_email']);
        foreach ($d['imputaciones'] ?? [] as $i) abort_unless(Comprobante::where('contact_id', $contact->id)->where('id', $i['comprobante_id'])->exists(), 422, "El comprobante {$i['comprobante_id']} no es de este cliente.");
        $d['notas'] = trim('Cobrado desde el CRM' . (! empty($d['crm_ref']) ? " ({$d['crm_ref']})" : '') . (! empty($d['notas']) ? ' · ' . $d['notas'] : ''));
        $cobro = $svc->registrar($contact, $d);
        $cobro->update(['modo' => 'crm']); // queda marcado de dónde vino
        return response()->json(['data' => ['id' => $cobro->id, 'numero' => $cobro->numeroFormateado(), 'fecha' => $cobro->fecha?->toDateString(), 'total' => (float) $cobro->total, 'a_cuenta' => (float) $cobro->a_cuenta, 'saldo_cliente' => round((float) $contact->fresh()->balance, 2), 'url_imprimir' => url("/clientes/cobros/{$cobro->id}/imprimir"),
            'cobrador' => $cobro->cobrador_id ? ['id' => $cobro->cobrador_id, 'nombre' => $cobro->cobrador?->nombre, 'email' => $cobro->cobrador?->email] : null, 'aviso' => $aviso]], 201);
    }
}
