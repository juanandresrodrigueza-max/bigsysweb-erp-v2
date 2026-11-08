<?php

namespace App\Services\Ventas;

use App\Models\Business;
use App\Models\CobroImputacion;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\PlanPago;
use Illuminate\Support\Collection;

// Fase 57 · Riesgo de mora por cliente, explicable: cómo pagó siempre (atraso promedio, cuántas veces tarde, el peor caso,
// si viene empeorando), lo vencido hoy y los planes de pago que no cumplió. Un número de 0 a 100 con sus motivos, sin caja negra.
class RiesgoMoraService
{
    // Historial de pagos de varios clientes de una vez (últimos 12 meses): atraso de cada cobro contra el vencimiento de la factura.
    public function historial(Collection $contactIds): array
    {
        $filas = CobroImputacion::query()->join('cobros', 'cobros.id', '=', 'cobro_imputaciones.cobro_id')->join('comprobantes', 'comprobantes.id', '=', 'cobro_imputaciones.comprobante_id')
            ->whereIn('cobros.contact_id', $contactIds)->where('cobros.estado', '!=', 'anulado')->where('cobros.fecha', '>=', today()->subYear()->toDateString())->whereNotNull('comprobantes.fecha_vto')
            ->orderBy('cobros.fecha')->get(['cobros.contact_id', 'cobros.fecha', 'comprobantes.fecha_vto', 'cobro_imputaciones.monto']);
        $out = [];
        foreach ($filas->groupBy('contact_id') as $cid => $g) {
            $atrasos = $g->map(fn($r) => \Carbon\Carbon::parse($r->fecha_vto)->diffInDays(\Carbon\Carbon::parse($r->fecha), false))->values();
            // Tendencia: la mitad más reciente de los pagos contra la mitad anterior (hacen falta al menos 3 pagos).
            $n = $atrasos->count(); $k = intdiv($n, 2); $ant = $atrasos->slice(0, $k); $ult = $atrasos->slice($k);
            $out[$cid] = ['pagos' => $n, 'atraso_prom' => round($atrasos->avg(), 1), 'pct_tarde' => $n ? round($atrasos->filter(fn($a) => $a > 2)->count() / $n * 100) : 0, 'max_atraso' => (int) $atrasos->max(),
                'tendencia' => $n >= 3 ? round($ult->avg() - $ant->avg(), 1) : 0];
        }
        return $out;
    }

    // Puntaje y nivel para una lista de clientes. $agg trae por cliente saldo y vencido (de deudores()); si no, se calcula.
    public function evaluar(Collection $contacts, array $agg = []): array
    {
        $ids = $contacts->pluck('id'); $hist = $this->historial($ids);
        $planesRotos = PlanPago::whereIn('contact_id', $ids)->where('estado', 'vigente')->whereHas('cuotas', fn($q) => $q->where('estado', 'vencida'))->pluck('contact_id')->countBy();
        $out = [];
        foreach ($contacts as $c) {
            $h = $hist[$c->id] ?? null; $saldo = (float) ($agg[$c->id]['saldo'] ?? $c->balance); $vencido = (float) ($agg[$c->id]['vencido'] ?? 0); $dias = (int) ($agg[$c->id]['dias'] ?? 0);
            $p = 0; $m = [];
            if (! $h) { $p += 35; $m[] = 'Sin historial de pagos todavía'; }
            else {
                $p += min(40, max(0, $h['atraso_prom']) * 0.8); if ($h['atraso_prom'] > 7) $m[] = "Paga en promedio {$h['atraso_prom']} días después del vencimiento"; elseif ($h['atraso_prom'] <= 2) $m[] = 'Suele pagar a tiempo';
                $p += $h['pct_tarde'] * 0.25; if ($h['pct_tarde'] >= 50) $m[] = "Pagó tarde el {$h['pct_tarde']} % de las veces";
                if ($h['tendencia'] >= 10) { $p += 10; $m[] = 'Viene empeorando: los últimos pagos tardaron ' . $h['tendencia'] . ' días más'; } elseif ($h['tendencia'] <= -10) { $p -= 5; $m[] = 'Viene mejorando'; }
            }
            if ($saldo > 0 && $vencido > 0) { $p += min(20, $vencido / $saldo * 20); $m[] = 'Tiene $ ' . number_format($vencido, 0, ',', '.') . ' vencidos' . ($dias ? " hace {$dias} días" : ''); }
            if ($dias > 60) $p += 10;
            if ((float) $c->credit_limit > 0 && $saldo > (float) $c->credit_limit) { $p += 10; $m[] = 'Pasó su límite de crédito'; }
            if ($planesRotos[$c->id] ?? 0) { $p += 15; $m[] = 'Tiene un plan de pago con cuotas vencidas'; }
            $p = (int) round(max(0, min(100, $p)));
            $out[$c->id] = ['puntaje' => $p, 'nivel' => $p < 30 ? 'bajo' : ($p < 60 ? 'medio' : 'alto'), 'motivos' => $m, 'historial' => $h];
        }
        return $out;
    }

    public function de(Contact $c): array
    {
        $vencido = (float) Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today())->sum('saldo');
        $saldo = (float) Comprobante::where('contact_id', $c->id)->pendientesCobro()->sum('saldo');
        $masVieja = Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today())->min('fecha_vto');
        return $this->evaluar(collect([$c]), [$c->id => ['saldo' => $saldo, 'vencido' => $vencido, 'dias' => $masVieja ? \Carbon\Carbon::parse($masVieja)->diffInDays(today()) : 0]])[$c->id];
    }

    // Facturas que vencen en los próximos días de clientes con riesgo medio o alto: para llamar antes, no después.
    public function paraLlamarAntes(Business $b, int $dias = 7): array
    {
        $fs = Comprobante::ventas()->pendientesCobro()->facturas()->whereBetween('fecha_vto', [today()->toDateString(), today()->addDays($dias)->toDateString()])->with('contact:id,name,phone,mobile,email,credit_limit,balance')->get();
        if ($fs->isEmpty()) return [];
        $contacts = $fs->pluck('contact')->filter()->unique('id')->values();
        $ev = $this->evaluar($contacts);
        return $fs->groupBy('contact_id')->map(function ($g) use ($ev) { $c = $g->first()->contact; $r = $ev[$c->id] ?? null; if (! $r || $r['nivel'] === 'bajo') return null;
            return ['id' => $c->id, 'nombre' => $c->name, 'telefono' => $c->mobile ?: $c->phone, 'nivel' => $r['nivel'], 'puntaje' => $r['puntaje'], 'motivos' => $r['motivos'], 'importe' => round($g->sum(fn($f) => (float) $f->saldo), 2), 'vence' => $g->min('fecha_vto')->format('d/m'), 'facturas' => $g->map(fn($f) => $f->numeroFormateado())->all()]; })->filter()->sortByDesc('puntaje')->values()->all();
    }
}
