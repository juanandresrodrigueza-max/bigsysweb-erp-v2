<?php

namespace App\Services\Ventas;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\CuentaFondos;
use App\Models\PedidoWeb;
use App\Services\Canales\WhatsappPedidosService;

// Avisos al dueño por WhatsApp: resumen del día y alertas críticas, sin entrar al sistema.
class AvisosDuenoService
{
    public const DEFAULT = ['activo' => false, 'whatsapp' => null, 'hora' => '21:00', 'resumen_diario' => true, 'criticas' => true, 'ultimo_resumen' => null, 'ultima_critica_id' => 0];

    public function __construct(private WhatsappPedidosService $wa) {}

    public function config(Business $b): array { return array_replace(self::DEFAULT, $b->avisos ?? []); }

    public function resumen(Business $b, ?\Carbon\Carbon $dia = null): string
    {
        $dia ??= today(); $id = $b->id;
        $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->whereDate('fecha', $dia);
        $tot = (float) (clone $ventas)->sum('total'); $n = (clone $ventas)->count();
        $ayer = (float) Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->whereDate('fecha', $dia->copy()->subDay())->sum('total');
        $cobros = (float) Cobro::withoutGlobalScopes()->where('business_id', $id)->where('estado', '!=', 'anulado')->whereDate('fecha', $dia)->sum('total');
        $caja = (float) CuentaFondos::withoutGlobalScopes()->where('business_id', $id)->where('activa', true)->where('tipo', 'caja')->sum('saldo');
        $banco = (float) CuentaFondos::withoutGlobalScopes()->where('business_id', $id)->where('activa', true)->where('tipo', 'banco')->sum('saldo');
        $pedidos = PedidoWeb::withoutGlobalScopes()->where('business_id', $id)->where('estado', 'nuevo')->count();
        $criticas = Alerta::withoutGlobalScopes()->where('business_id', $id)->whereNull('resuelta_en')->where('severidad', 'critica')->latest()->limit(3)->get();
        $vencido = (float) Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->where('saldo', '>', 0.005)->where('fecha_vto', '<', dia)->sum('saldo');
        $f = fn($v) => '$ ' . number_format($v, 0, ',', '.');
        $var = $ayer > 0 ? round(($tot - $ayer) / $ayer * 100) : null;
        $t = "*{$b->name}* · resumen del " . $dia->format('d/m') . "\n";
        $t .= "💰 Ventas: {$f($tot)} en {$n} comprobantes" . ($var !== null ? ' (' . ($var >= 0 ? '+' : '') . "{$var}% vs ayer)" : '') . "\n";
        $t .= "💵 Cobrado: {$f($cobros)}\n🏦 Caja {$f($caja)} · Banco {$f($banco)}\n";
        if ($vencido > 0) $t .= "⚠️ Vencido por cobrar: {$f($vencido)}\n";
        if ($pedidos) $t .= "🛒 {$pedidos} pedido/s web sin confirmar\n";
        foreach ($criticas as $a) $t .= "🔴 {$a->titulo}\n";
        return trim($t);
    }

    // Manda el resumen si es la hora configurada y no se mandó hoy. Devuelve true si envió.
    public function enviarResumenSiCorresponde(Business $b): bool
    {
        $c = $this->config($b);
        if (! $c['activo'] || ! $c['resumen_diario'] || ! $c['whatsapp']) return false;
        if (($c['ultimo_resumen'] ?? null) === today()->toDateString()) return false;
        if (now()->format('H:i') < $c['hora']) return false;
        $r = $this->wa->responder($b, $c['whatsapp'], $this->resumen($b));
        $b->update(['avisos' => array_replace($c, ['ultimo_resumen' => today()->toDateString(), 'ultimo_link' => $r['link'] ?? null, 'ultimo_enviado' => $r['enviado']])]);
        return true;
    }

    // Alertas críticas nuevas desde la última vez.
    public function enviarCriticas(Business $b): int
    {
        $c = $this->config($b);
        if (! $c['activo'] || ! $c['criticas'] || ! $c['whatsapp']) return 0;
        $nuevas = Alerta::withoutGlobalScopes()->where('business_id', $b->id)->where('severidad', 'critica')->whereNull('resuelta_en')->where('id', '>', (int) ($c['ultima_critica_id'] ?? 0))->orderBy('id')->get();
        if ($nuevas->isEmpty()) return 0;
        $texto = "*{$b->name}* · " . $nuevas->count() . " alerta/s crítica/s:\n" . $nuevas->map(fn($a) => "🔴 {$a->titulo}" . ($a->detalle ? ' — ' . mb_substr($a->detalle, 0, 90) : ''))->implode("\n");
        $this->wa->responder($b, $c['whatsapp'], $texto);
        $b->update(['avisos' => array_replace($c, ['ultima_critica_id' => $nuevas->max('id')])]);
        return $nuevas->count();
    }
}
