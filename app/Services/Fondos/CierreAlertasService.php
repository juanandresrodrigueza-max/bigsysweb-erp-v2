<?php

namespace App\Services\Fondos;

use App\Models\Alerta;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Avisos\CelularService;

// Fase 43.5: cada cierre de caja se compara con lo normal de esa caja (los últimos 20 cierres). Si sale de lo normal
// —mucho efectivo, en cero o con pocos comprobantes— queda una alerta y le llega al celular al dueño.
class CierreAlertasService
{
    public function revisar(TurnoCaja $t): array
    {
        $prev = TurnoCaja::where('cuenta_fondos_id', $t->cuenta_fondos_id)->whereNotNull('cierre')->where('id', '!=', $t->id)->latest('cierre')->limit(20)->get();
        $comp = fn(TurnoCaja $x) => $x->movimientos()->where('origen', 'cobro')->count();
        $efectivo = (float) $t->saldo_contado; $n = $comp($t);
        $raros = [];
        if ($efectivo <= 0.005 && $n > 0) $raros[] = 'cerró en cero con ' . $n . ' cobros';
        if ($prev->count() >= 5) {
            $mediaEf = $prev->avg(fn($x) => (float) $x->saldo_contado); $mediaN = $prev->avg($comp);
            if ($mediaEf > 0 && $efectivo > $mediaEf * 2.5) $raros[] = 'mucho efectivo: $ ' . number_format($efectivo, 0, ',', '.') . ' (lo normal es $ ' . number_format($mediaEf, 0, ',', '.') . ')';
            if ($mediaN >= 5 && $n < $mediaN * 0.3) $raros[] = "pocos comprobantes: {$n} (lo normal son " . round($mediaN) . ')';
        }
        if (! $raros) return [];
        $caja = $t->cuenta?->nombre ?? 'la caja'; $quien = $t->user?->name;
        Alerta::emitir(['business_id' => $t->business_id, 'tipo' => 'cierre_raro', 'modelo' => 'TurnoCaja', 'modelo_id' => $t->id, 'modulo' => 'fondos', 'severidad' => 'aviso',
            'titulo' => "Cierre fuera de lo normal en {$caja}", 'detalle' => ucfirst(implode(' · ', $raros)) . ($quien ? " · cerró {$quien} a las " . $t->cierre?->format('H:i') : ''), 'url' => '/fondos/cierres']);
        $dueno = User::find(\App\Models\Business::whereKey($t->business_id)->value('owner_id'));
        if ($dueno) try { app(CelularService::class)->enviar($dueno, "Cierre raro en {$caja}", ucfirst(implode(' · ', $raros)), '/fondos/cierres'); } catch (\Throwable) {}
        return $raros;
    }
}
