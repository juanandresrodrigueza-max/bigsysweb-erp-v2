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

    // Fase 116: todos los que deben, para que el CRM gestione la cobranza (recordatorios, tareas, promesas). Paginado; ?solo_vencidos=1.
    public function deudores(Request $request)
    {
        $porPagina = max(1, min(200, (int) $request->query('por_pagina', 100)));
        $q = Contact::customers()->where('is_active', true)->where('balance', '>', 0.005)->orderByDesc('balance');
        if ($request->boolean('solo_vencidos')) $q->whereIn('id', Comprobante::pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->select('contact_id'));
        $pag = $q->paginate($porPagina);
        $ids = $pag->getCollection()->pluck('id');
        $vencidos = Comprobante::whereIn('contact_id', $ids)->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->orderBy('fecha_vto')->get()->groupBy('contact_id');
        $ultimos = Cobro::whereIn('contact_id', $ids)->where('estado', '!=', 'anulado')->orderByDesc('fecha')->orderByDesc('id')->get()->unique('contact_id')->keyBy('contact_id');
        $data = $pag->getCollection()->map(function (Contact $c) use ($vencidos, $ultimos) {
            $v = $vencidos->get($c->id, collect()); $u = $ultimos->get($c->id);
            return ['contact_id' => $c->id, 'crm_external_id' => $c->crm_external_id, 'nombre' => $c->name, 'cuit' => $c->cuit, 'email' => $c->email, 'telefono' => $c->mobile ?: $c->phone,
                'saldo' => round((float) $c->balance, 2), 'vencido' => round((float) $v->sum('saldo'), 2), 'dias_mayor_atraso' => (int) ($v->map(fn($x) => $x->fecha_vto->diffInDays(today()))->max() ?? 0),
                'comprobantes_vencidos' => $v->map(fn($x) => ['id' => $x->id, 'tipo' => $x->tipo, 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha?->toDateString(), 'vence' => $x->fecha_vto?->toDateString(), 'saldo' => (float) $x->saldo, 'dias' => $x->fecha_vto->diffInDays(today())])->values()->all(),
                'ultimo_cobro' => $u ? ['id' => $u->id, 'numero' => $u->numeroFormateado(), 'fecha' => $u->fecha?->toDateString(), 'total' => (float) $u->total] : null,
                'dias_pago' => (int) $c->dias_pago, 'limite_credito' => (float) $c->credit_limit ?: null, 'url' => Busca::url("/clientes/{$c->id}")];
        })->values();
        return response()->json(['data' => $data, 'meta' => ['total' => $pag->total(), 'pagina' => $pag->currentPage(), 'por_pagina' => $pag->perPage(), 'paginas' => $pag->lastPage(), 'saldo_total' => round((float) Contact::customers()->where('balance', '>', 0)->sum('balance'), 2), 'actualizado' => now()->toIso8601String()]]);
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
            'crm_payment_id' => 'nullable|string|max:80', // Fase 116: si el CRM reintenta, el mismo pago no entra dos veces
        ]);
        if (! empty($d['crm_payment_id']) && ($ya = Cobro::where('crm_payment_id', $d['crm_payment_id'])->where('estado', '!=', 'anulado')->first())) return response()->json(['data' => $this->salida($ya, $contact->fresh(), null) + ['duplicado' => true]], 200);
        // Quién cobró, para la comisión: por id del ERP o por mail. Si el mail es de un usuario del ERP que todavía no es vendedor, se le crea la
        // ficha de vendedor (0 % hasta que el dueño le cargue el porcentaje): el recibo nunca queda sin cobrador por eso (hallazgo de la prueba 3.4).
        $aviso = null;
        if (! empty($d['vendedor_email']) && empty($d['vendedor_id'])) {
            $mail = strtolower(trim($d['vendedor_email']));
            $v = \App\Models\Vendedor::where('activo', true)->whereRaw('lower(email) = ?', [$mail])->first();
            $u = $v ? null : \App\Models\User::where('business_id', $contact->business_id)->whereRaw('lower(email) = ?', [$mail])->where('status', 'active')->first();
            if (! $v && $u) {
                // Primero una ficha de vendedor sin usuario ligado con el mismo nombre (la cargaron a mano): se la engancha en vez de duplicarla.
                $v = \App\Models\Vendedor::deUsuario($u->id) ?? \App\Models\Vendedor::where('activo', true)->whereNull('user_id')->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($u->name))])->first();
                if ($v && ! $v->user_id) $v->update(['user_id' => $u->id, 'email' => $v->email ?: $mail]);
                $v = $v ?? \App\Models\Vendedor::create(['business_id' => $contact->business_id, 'user_id' => $u->id, 'nombre' => $u->name, 'email' => $mail, 'comision_venta' => 0, 'comision_cobro' => 0, 'activo' => true]);
            }
            if ($v) $d['vendedor_id'] = $v->id; else $aviso = "{$mail} no es usuario ni vendedor del ERP: el recibo queda sin cobrador.";
        }
        if (! empty($d['vendedor_id'])) { abort_unless(\App\Models\Vendedor::where('id', $d['vendedor_id'])->exists(), 422, 'Ese vendedor no existe en el ERP.'); $d['cobrador_id'] = (int) $d['vendedor_id']; }
        unset($d['vendedor_email']);
        foreach ($d['imputaciones'] ?? [] as $i) abort_unless(Comprobante::where('contact_id', $contact->id)->where('id', $i['comprobante_id'])->exists(), 422, "El comprobante {$i['comprobante_id']} no es de este cliente.");
        $d['notas'] = trim('Cobrado desde el CRM' . (! empty($d['crm_ref']) ? " ({$d['crm_ref']})" : '') . (! empty($d['notas']) ? ' · ' . $d['notas'] : ''));
        $d['modo'] = 'crm'; // queda marcado de dónde vino (y el aviso al CRM sale con el crm_payment_id)
        $cobro = $svc->registrar($contact, $d);
        return response()->json(['data' => $this->salida($cobro, $contact->fresh(), $aviso) + ['duplicado' => false]], 201);
    }

    private function salida(Cobro $cobro, Contact $contact, ?string $aviso): array
    {
        return ['id' => $cobro->id, 'numero' => $cobro->numeroFormateado(), 'fecha' => $cobro->fecha?->toDateString(), 'total' => (float) $cobro->total, 'a_cuenta' => (float) $cobro->a_cuenta, 'saldo_cliente' => round((float) $contact->balance, 2), 'crm_payment_id' => $cobro->crm_payment_id,
            'url_imprimir' => url("/clientes/cobros/{$cobro->id}/imprimir"), 'cobrador' => $cobro->cobrador_id ? ['id' => $cobro->cobrador_id, 'nombre' => $cobro->cobrador?->nombre, 'email' => $cobro->cobrador?->email] : null, 'aviso' => $aviso];
    }
}
