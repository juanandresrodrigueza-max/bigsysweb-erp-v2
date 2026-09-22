<?php

namespace App\Console\Commands;

use App\Models\Acopio;
use App\Models\Alerta;
use App\Models\Business;
use App\Models\Cheque;
use App\Models\CuentaFondos;
use App\Models\TurnoCaja;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use Illuminate\Console\Command;

// Evalúa las reglas de alerta de cada empresa. Se ejecuta cada 15 minutos.
class GenerarAlertas extends Command
{
    protected $signature = 'alertas:generar';
    protected $description = 'Genera y resuelve alertas de stock, mora, pagos, cheques, cajas, presupuestos y acopios';

    public function handle(): int
    {
        foreach (Business::where('is_active', true)->get() as $b) {
            $id = $b->id;

            // Stock bajo mínimo
            $bajo = Product::withoutGlobalScopes()->where('business_id', $id)->where('active', true)->whereColumn('stock', '<=', 'stock_min')->get();
            foreach ($bajo as $p) {
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'stock_minimo', 'modelo' => 'Product', 'modelo_id' => $p->id],
                    ['business_location_id' => $p->business_location_id, 'modulo' => 'stock', 'severidad' => (float) $p->stock <= 0 ? 'critica' : 'aviso', 'titulo' => "{$p->name} bajo mínimo", 'detalle' => "Stock {$p->stock} {$p->unit}, mínimo {$p->stock_min}.", 'url' => '/stock', 'resuelta_en' => null]
                );
            }
            Alerta::withoutGlobalScopes()->where('business_id', $id)->where('tipo', 'stock_minimo')->whereNull('resuelta_en')->whereNotIn('modelo_id', $bajo->pluck('id'))->update(['resuelta_en' => now()]);

            // Mora: facturas vencidas con saldo
            $vencidas = Comprobante::withoutGlobalScopes()->where('business_id', $id)->ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005)->whereDate('fecha_vto', '<', today())->get()->groupBy('contact_id');
            foreach ($vencidas as $contactId => $comps) {
                $c = Contact::withoutGlobalScopes()->find($contactId);
                if (! $c) continue;
                $deuda = $comps->sum(fn($x) => (float) $x->saldo);
                $dias = today()->diffInDays($comps->min('fecha_vto'));
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'mora', 'modelo' => 'Contact', 'modelo_id' => $c->id],
                    ['modulo' => 'clientes', 'severidad' => $dias > 30 ? 'critica' : 'aviso', 'titulo' => "{$c->name} con saldo vencido", 'detalle' => 'Debe $ ' . number_format($deuda, 0, ',', '.') . " vencido hace {$dias} días ({$comps->count()} comprobante/s).", 'url' => "/clientes/{$c->id}", 'resuelta_en' => null]
                );
            }
            Alerta::withoutGlobalScopes()->where('business_id', $id)->where('tipo', 'mora')->whereNull('resuelta_en')->whereNotIn('modelo_id', $vencidas->keys())->update(['resuelta_en' => now()]);

            // Presupuestos sin respuesta hace más de 7 días
            $pres = Comprobante::withoutGlobalScopes()->where('business_id', $id)->ventas()->emitidos()->where('tipo', 'PRE')->whereDate('fecha', '<', today()->subDays(7))->whereDoesntHave('derivados')->get();
            foreach ($pres as $p) {
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'presupuesto_sin_respuesta', 'modelo' => 'Comprobante', 'modelo_id' => $p->id],
                    ['business_location_id' => $p->business_location_id, 'modulo' => 'comprobantes', 'severidad' => 'info', 'titulo' => "Presupuesto {$p->numeroFormateado()} sin respuesta", 'detalle' => 'Emitido el ' . $p->fecha->format('d/m') . ' por $ ' . number_format((float) $p->total, 0, ',', '.') . '. Conviene hacer seguimiento.', 'url' => "/comprobantes/{$p->id}", 'resuelta_en' => null]
                );
            }

            // Órdenes de pago: facturas de compra vencidas con saldo
            $porPagar = Comprobante::withoutGlobalScopes()->where('business_id', $id)->compras()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005)->whereDate('fecha_vto', '<=', today()->addDays(3))->get()->groupBy('contact_id');
            foreach ($porPagar as $contactId => $comps) {
                $c = Contact::withoutGlobalScopes()->find($contactId);
                if (! $c) continue;
                $monto = $comps->sum(fn($x) => (float) $x->saldo);
                $vencidas = $comps->filter(fn($x) => $x->fecha_vto && $x->fecha_vto->lt(today()))->count();
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'pago_vence', 'modelo' => 'Contact', 'modelo_id' => $c->id],
                    ['modulo' => 'proveedores', 'severidad' => $vencidas ? 'aviso' : 'info', 'titulo' => ($vencidas ? 'Pago vencido a ' : 'Pago próximo a ') . $c->name, 'detalle' => '$ ' . number_format($monto, 0, ',', '.') . " en {$comps->count()} factura/s" . ($vencidas ? ", {$vencidas} vencida/s." : ' que vencen en 3 días.'), 'url' => "/proveedores/{$c->id}", 'resuelta_en' => null]
                );
            }
            Alerta::withoutGlobalScopes()->where('business_id', $id)->where('tipo', 'pago_vence')->whereNull('resuelta_en')->whereNotIn('modelo_id', $porPagar->keys())->update(['resuelta_en' => now()]);

            // Cheques: de terceros en cartera que vencen en 5 días, propios entregados a debitar, rechazados sin resolver
            $cheques = Cheque::withoutGlobalScopes()->where('business_id', $id)->where(function ($q) {
                $q->where(fn($w) => $w->where('tipo', 'tercero')->where('estado', 'cartera')->whereDate('fecha_pago', '<=', today()->addDays(5)))
                  ->orWhere(fn($w) => $w->where('tipo', 'propio')->where('estado', 'entregado')->whereDate('fecha_pago', '<=', today()->addDays(3)))
                  ->orWhere('estado', 'rechazado');
            })->get();
            foreach ($cheques as $ch) {
                [$sev, $tit] = match (true) {
                    $ch->estado === 'rechazado' => ['critica', "Cheque rechazado {$ch->numero} ({$ch->emisor})"],
                    $ch->tipo === 'propio' => [$ch->fecha_pago->isPast() ? 'critica' : 'aviso', "Cheque propio {$ch->numero} se debita el " . $ch->fecha_pago->format('d/m')],
                    default => ['info', "Cheque {$ch->numero} de {$ch->emisor} cobrable el " . $ch->fecha_pago->format('d/m')],
                };
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'cheque', 'modelo' => 'Cheque', 'modelo_id' => $ch->id],
                    ['modulo' => 'fondos', 'severidad' => $sev, 'titulo' => $tit, 'detalle' => '$ ' . number_format((float) $ch->monto, 0, ',', '.') . " · {$ch->banco}", 'url' => '/fondos/cheques', 'resuelta_en' => null]
                );
            }
            Alerta::withoutGlobalScopes()->where('business_id', $id)->where('tipo', 'cheque')->whereNull('resuelta_en')->whereNotIn('modelo_id', $cheques->pluck('id'))->update(['resuelta_en' => now()]);

            // Cajas: turno abierto hace más de 14 horas; cuentas bajo el saldo mínimo
            $turnos = TurnoCaja::withoutGlobalScopes()->where('business_id', $id)->whereNull('cierre')->where('apertura', '<', now()->subHours(14))->with('cuenta')->get();
            foreach ($turnos as $t) {
                Alerta::withoutGlobalScopes()->updateOrCreate(['business_id' => $id, 'tipo' => 'caja_abierta', 'modelo' => 'TurnoCaja', 'modelo_id' => $t->id],
                    ['business_location_id' => $t->cuenta?->business_location_id, 'modulo' => 'fondos', 'severidad' => 'aviso', 'titulo' => "Caja {$t->cuenta?->nombre} sin cerrar", 'detalle' => 'Turno abierto desde ' . $t->apertura->format('d/m H:i') . '.', 'url' => '/fondos', 'resuelta_en' => null]);
            }
            Alerta::withoutGlobalScopes()->where('business_id', $id)->where('tipo', 'caja_abierta')->whereNull('resuelta_en')->whereNotIn('modelo_id', $turnos->pluck('id'))->update(['resuelta_en' => now()]);
            $bajas = CuentaFondos::withoutGlobalScopes()->where('business_id', $id)->where('activa', true)->where('saldo_minimo', '>', 0)->whereColumn('saldo', '<', 'saldo_minimo')->get();
            foreach ($bajas as $cu) {
                Alerta::withoutGlobalScopes()->updateOrCreate(['business_id' => $id, 'tipo' => 'saldo_minimo', 'modelo' => 'CuentaFondos', 'modelo_id' => $cu->id],
                    ['modulo' => 'fondos', 'severidad' => 'aviso', 'titulo' => "{$cu->nombre} bajo el saldo mínimo", 'detalle' => 'Saldo $ ' . number_format((float) $cu->saldo, 0, ',', '.') . ', mínimo $ ' . number_format((float) $cu->saldo_minimo, 0, ',', '.') . '.', 'url' => '/fondos', 'resuelta_en' => null]);
            }
            Alerta::withoutGlobalScopes()->where('business_id', $id)->where('tipo', 'saldo_minimo')->whereNull('resuelta_en')->whereNotIn('modelo_id', $bajas->pluck('id'))->update(['resuelta_en' => now()]);

            // Acopios por vencer (15 días) o vencidos
            $acopios = Acopio::withoutGlobalScopes()->where('business_id', $id)->whereIn('estado', ['abierto', 'parcial', 'vencido'])->whereNotNull('fecha_limite')->whereDate('fecha_limite', '<=', today()->addDays(15))->with('contact')->get();
            foreach ($acopios as $a) {
                $vencido = $a->fecha_limite->isPast();
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'acopio_vence', 'modelo' => 'Acopio', 'modelo_id' => $a->id],
                    ['business_location_id' => $a->business_location_id, 'modulo' => 'clientes', 'severidad' => $vencido ? 'aviso' : 'info', 'titulo' => ($vencido ? 'Acopio vencido: ' : 'Acopio por vencer: ') . $a->contact?->name, 'detalle' => 'Límite ' . $a->fecha_limite->format('d/m/Y') . '. Quedan ítems sin retirar.', 'url' => "/clientes/{$a->contact_id}", 'resuelta_en' => null]
                );
            }
        }
        $this->info('Alertas actualizadas.');
        return self::SUCCESS;
    }
}
