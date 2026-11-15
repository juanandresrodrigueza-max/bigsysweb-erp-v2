<?php

namespace App\Services\Contabilidad;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\CobroMedio;
use App\Models\Comprobante;
use App\Models\ComprobanteImpuesto;
use App\Models\PosicionIva;
use App\Models\User;
use Carbon\Carbon;

// Fase 99: la posición mensual de IVA como la arma el contador: débito fiscal de las ventas menos crédito fiscal de las compras, menos percepciones y
// retenciones de IVA sufridas, con el saldo a favor del mes anterior arrastrado (técnico: sólo contra IVA; libre: también contra otros impuestos).
class PosicionIvaService
{
    private const CON_IVA = ['FA', 'FB', 'NCA', 'NCB', 'NDA', 'NDB', 'FM', 'NCM', 'NDM'];

    public function calcular(Business $b, string $mes): array
    {
        $ini = Carbon::parse($mes . '-01')->startOfMonth(); $fin = $ini->copy()->endOfMonth(); $d = $ini->toDateString(); $h = $fin->toDateString();
        $base = fn(string $dir) => Comprobante::withoutGlobalScopes()->with('items:id,comprobante_id,alicuota_iva,iva')->where('business_id', $b->id)->where('direccion', $dir)->where('estado', 'emitido')->fiscales()->whereIn('tipo', self::CON_IVA)->whereBetween('fecha', [$d, $h])->get();
        $porAl = function ($cs) { $r = ['21' => 0.0, '10.5' => 0.0, '27' => 0.0, 'otras' => 0.0, 'neto' => 0.0, 'total' => 0.0, 'comprobantes' => 0];
            foreach ($cs as $c) { $s = $c->esNotaCredito() ? -1 : 1; $r['comprobantes']++; $r['neto'] += $s * ((float) $c->neto - (float) $c->descuento); $r['total'] += $s * (float) $c->iva;
                foreach ($c->items as $it) { $al = (float) $it->alicuota_iva; $k = $al == 21 ? '21' : ($al == 10.5 ? '10.5' : ($al == 27 ? '27' : 'otras')); $r[$k] += $s * (float) $it->iva; } }
            return array_map(fn($v) => is_int($v) ? $v : round($v, 2), $r); };
        $ventas = $porAl($base('venta')); $compras = $porAl($base('compra'));
        $percepciones = round((float) ComprobanteImpuesto::whereHas('comprobante', fn($q) => $q->withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$d, $h]))->where('tipo', 'perc_iva')->sum('monto'), 2);
        $retenciones = round((float) CobroMedio::where('medio', 'retencion')->whereHas('cobro', fn($q) => $q->withoutGlobalScopes()->where('business_id', $b->id)->where('estado', '!=', 'anulado')->whereBetween('fecha', [$d, $h]))->get()->filter(fn($m) => in_array(strtolower((string) (($m->datos ?? [])['impuesto'] ?? '')), ['iva'], true))->sum('monto'), 2);
        $prev = PosicionIva::withoutGlobalScopes()->where('business_id', $b->id)->where('mes', '<', $mes)->orderByDesc('mes')->first();
        $actual = PosicionIva::withoutGlobalScopes()->where('business_id', $b->id)->where('mes', $mes)->first();
        $antTec = (float) ($prev?->saldo_tecnico ?? 0); $antLib = (float) ($prev?->saldo_libre ?? 0); $ajuste = (float) ($actual?->ajuste ?? 0);
        $r = $this->resolver($ventas['total'], $compras['total'], $percepciones, $retenciones, $antTec, $antLib, $ajuste);
        return ['mes' => $mes, 'periodo' => ucfirst($ini->locale('es')->isoFormat('MMMM YYYY')), 'ventas' => $ventas, 'compras' => $compras, 'debito' => $ventas['total'], 'credito' => $compras['total'], 'percepciones' => $percepciones, 'retenciones' => $retenciones,
            'saldo_anterior_tecnico' => $antTec, 'saldo_anterior_libre' => $antLib, 'anterior_mes' => $prev?->mes, 'anterior_estado' => $prev?->estado, 'ajuste' => $ajuste, 'ajuste_nota' => $actual?->ajuste_nota,
            'estado' => $actual?->estado ?? 'sin_guardar', 'cerrada_en' => $actual?->cerrada_en?->format('d/m/Y H:i'), 'id' => $actual?->id, 'vencimiento' => $this->vencimiento($b, $ini)] + $r;
    }

    // La cuenta: técnico = débito − crédito − saldo técnico anterior (+ ajuste). Si da a pagar, se descuenta el libre disponible (anterior + percepciones + retenciones).
    public function resolver(float $debito, float $credito, float $percepciones, float $retenciones, float $antTec, float $antLib, float $ajuste = 0): array
    {
        $tecnico = round($debito + $ajuste - $credito - $antTec, 2);
        $libre = round($antLib + $percepciones + $retenciones, 2);
        if ($tecnico > 0) { $aPagar = round(max(0, $tecnico - $libre), 2); $saldoLibre = round(max(0, $libre - $tecnico), 2); $saldoTec = 0.0; }
        else { $aPagar = 0.0; $saldoTec = round(-$tecnico, 2); $saldoLibre = $libre; }
        return ['tecnico' => $tecnico, 'libre_disponible' => $libre, 'a_pagar' => $aPagar, 'saldo_tecnico' => $saldoTec, 'saldo_libre' => $saldoLibre, 'resultado' => $aPagar > 0 ? 'a_pagar' : (($saldoTec + $saldoLibre) > 0 ? 'a_favor' : 'cero')];
    }

    // Vence el mes siguiente, del 18 al 22 según la terminación del CUIT (calendario de ARCA, puede moverse por feriados).
    public function vencimiento(Business $b, Carbon $ini): string
    {
        $t = (int) substr(preg_replace('/\D/', '', (string) $b->cuit), -1);
        $dia = match (true) { $t <= 1 => 18, $t <= 3 => 19, $t <= 5 => 20, $t <= 7 => 21, default => 22 };
        return $ini->copy()->addMonth()->day($dia)->toDateString();
    }

    public function guardar(Business $b, string $mes, User $u, array $d = [], bool $cerrar = false): PosicionIva
    {
        $p = PosicionIva::withoutGlobalScopes()->firstOrNew(['business_id' => $b->id, 'mes' => $mes]);
        abort_if($p->exists && $p->estado === 'cerrada' && ! array_key_exists('reabrir', $d), 422, 'Ese mes ya está cerrado: reabrilo para cambiarlo.');
        if (array_key_exists('ajuste', $d)) $p->ajuste = (float) ($d['ajuste'] ?? 0);
        if (array_key_exists('ajuste_nota', $d)) $p->ajuste_nota = $d['ajuste_nota'];
        $p->business_id = $b->id; $p->save();
        $c = $this->calcular($b, $mes);
        $p->fill(['debito' => $c['debito'], 'credito' => $c['credito'], 'percepciones' => $c['percepciones'], 'retenciones' => $c['retenciones'], 'saldo_anterior_tecnico' => $c['saldo_anterior_tecnico'], 'saldo_anterior_libre' => $c['saldo_anterior_libre'], 'a_pagar' => $c['a_pagar'], 'saldo_tecnico' => $c['saldo_tecnico'], 'saldo_libre' => $c['saldo_libre'],
            'detalle' => ['ventas' => $c['ventas'], 'compras' => $c['compras'], 'tecnico' => $c['tecnico'], 'libre_disponible' => $c['libre_disponible'], 'vencimiento' => $c['vencimiento']],
            'estado' => $cerrar ? 'cerrada' : 'borrador', 'cerrada_por' => $cerrar ? $u->id : null, 'cerrada_en' => $cerrar ? now() : null])->save();
        AuditLog::registrar($cerrar ? 'editar' : ($p->wasRecentlyCreated ? 'crear' : 'editar'), $p, ($cerrar ? 'Cerró' : 'Guardó') . " la posición de IVA {$mes}: " . ($c['a_pagar'] > 0 ? 'a pagar $ ' . number_format($c['a_pagar'], 2, ',', '.') : 'a favor $ ' . number_format($c['saldo_tecnico'] + $c['saldo_libre'], 2, ',', '.')));
        return $p;
    }

    public function reabrir(PosicionIva $p): void { $p->forceFill(['estado' => 'borrador', 'cerrada_por' => null, 'cerrada_en' => null])->save(); AuditLog::registrar('editar', $p, "Reabrió la posición de IVA {$p->mes}"); }

    public function historial(Business $b, int $meses = 12): array
    {
        return PosicionIva::withoutGlobalScopes()->where('business_id', $b->id)->orderByDesc('mes')->limit($meses)->get()
            ->map(fn($p) => ['id' => $p->id, 'mes' => $p->mes, 'periodo' => ucfirst(Carbon::parse($p->mes . '-01')->locale('es')->isoFormat('MMM YYYY')), 'debito' => (float) $p->debito, 'credito' => (float) $p->credito, 'percepciones' => (float) $p->percepciones, 'retenciones' => (float) $p->retenciones, 'a_pagar' => (float) $p->a_pagar, 'saldo_tecnico' => (float) $p->saldo_tecnico, 'saldo_libre' => (float) $p->saldo_libre, 'estado' => $p->estado, 'ajuste' => (float) $p->ajuste])->all();
    }

    // El texto que se le manda al contador.
    public function resumenTexto(Business $b, array $c): string
    {
        $f = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        $l = ["Posición IVA {$c['periodo']} · {$b->name} (CUIT {$b->cuit})", "Débito fiscal (ventas): {$f($c['debito'])} [21 %: {$f($c['ventas']['21'])} · 10,5 %: {$f($c['ventas']['10.5'])} · 27 %: {$f($c['ventas']['27'])}]", "Crédito fiscal (compras): {$f($c['credito'])} [21 %: {$f($c['compras']['21'])} · 10,5 %: {$f($c['compras']['10.5'])} · 27 %: {$f($c['compras']['27'])}]"];
        if ($c['ajuste']) $l[] = "Ajuste: {$f($c['ajuste'])}" . ($c['ajuste_nota'] ? " ({$c['ajuste_nota']})" : '');
        $l[] = "Saldo técnico anterior: {$f($c['saldo_anterior_tecnico'])} · Saldo libre anterior: {$f($c['saldo_anterior_libre'])}";
        $l[] = "Percepciones de IVA sufridas: {$f($c['percepciones'])} · Retenciones de IVA sufridas: {$f($c['retenciones'])}";
        $l[] = $c['a_pagar'] > 0 ? "A PAGAR: {$f($c['a_pagar'])} (vence el " . Carbon::parse($c['vencimiento'])->format('d/m/Y') . ')' : 'Sin impuesto a pagar.';
        $l[] = "Saldo a favor que pasa al mes siguiente: técnico {$f($c['saldo_tecnico'])} · libre disponibilidad {$f($c['saldo_libre'])}";
        return implode("\n", $l);
    }
}
