<?php

namespace App\Console\Commands;

use App\Models\Acopio;
use App\Models\Alerta;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use Illuminate\Console\Command;

// Evalúa las reglas de alerta de cada empresa. Se ejecuta cada 15 minutos.
class GenerarAlertas extends Command
{
    protected $signature = 'alertas:generar';
    protected $description = 'Genera y resuelve alertas de stock, mora, presupuestos y acopios';

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
            $vencidas = Comprobante::withoutGlobalScopes()->where('business_id', $id)->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005)->whereDate('fecha_vto', '<', today())->get()->groupBy('contact_id');
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
            $pres = Comprobante::withoutGlobalScopes()->where('business_id', $id)->emitidos()->where('tipo', 'PRE')->whereDate('fecha', '<', today()->subDays(7))->whereDoesntHave('derivados')->get();
            foreach ($pres as $p) {
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $id, 'tipo' => 'presupuesto_sin_respuesta', 'modelo' => 'Comprobante', 'modelo_id' => $p->id],
                    ['business_location_id' => $p->business_location_id, 'modulo' => 'comprobantes', 'severidad' => 'info', 'titulo' => "Presupuesto {$p->numeroFormateado()} sin respuesta", 'detalle' => 'Emitido el ' . $p->fecha->format('d/m') . ' por $ ' . number_format((float) $p->total, 0, ',', '.') . '. Conviene hacer seguimiento.', 'url' => "/comprobantes/{$p->id}", 'resuelta_en' => null]
                );
            }

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
