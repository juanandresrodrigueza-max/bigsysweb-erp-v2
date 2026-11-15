<?php

namespace App\Services\Agentes;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Propuesta;
use App\Models\TurnoCaja;
use App\Models\User;

// Fase 107 · Control interno: compara a cada usuario con el resto del equipo en los últimos 30 días: anulaciones, descuentos regalados,
// cierres de caja que no cuadran, ventas muy por debajo de la lista y cargas a deshora. Si alguien se sale de lo normal (al menos el
// doble y medio que el promedio de los demás, y con un piso que importe), se lo cuenta al dueño con la evidencia. Sólo avisa:
// aprobar es "lo vi". Además deja una alerta crítica que llega al celular.
class ControlInternoAgente implements Agente
{
    public const DIAS = 30;
    public const FACTOR = 2.5;

    public function nombre(): string { return 'control'; }

    // Métricas por usuario y el análisis: qué usuarios se destacan y en qué.
    public function analizar(Business $b, int $dias = self::DIAS): array
    {
        $bid = $b->id; $desde = now()->subDays($dias);
        $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'FX'])->where('emitido_en', '>=', $desde);
        $porUsuario = (clone $ventas)->selectRaw('user_id, COUNT(*) n, COALESCE(SUM(total),0) total')->groupBy('user_id')->get()->keyBy('user_id');
        if ($porUsuario->isEmpty()) return ['dias' => $dias, 'usuarios' => [], 'hallazgos' => []];
        $ids = $porUsuario->keys()->filter()->all();
        $usuarios = User::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id');
        $items = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $bid)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->where('comprobantes.emitido_en', '>=', $desde);
        $descuentos = (clone $items)->where('comprobante_items.descuento', '>', 0)->selectRaw('comprobantes.user_id, COUNT(*) n, COALESCE(SUM(comprobante_items.descuento),0) plata')->groupBy('comprobantes.user_id')->get()->keyBy('user_id');
        $bajoLista = (clone $items)->join('products', 'products.id', '=', 'comprobante_items.product_id')->where('products.price', '>', 0)->whereRaw('comprobante_items.precio_unit < products.price * 0.7')->selectRaw('comprobantes.user_id, COUNT(*) n')->groupBy('comprobantes.user_id')->get()->keyBy('user_id');
        $anulados = AuditLog::withoutGlobalScopes()->where('business_id', $bid)->where('accion', 'anular')->whereIn('modelo', ['Comprobante', 'Cobro', 'Pago'])->where('created_at', '>=', $desde)->selectRaw('user_id, COUNT(*) n')->groupBy('user_id')->pluck('n', 'user_id');
        $cajas = TurnoCaja::withoutGlobalScopes()->where('business_id', $bid)->whereNotNull('cierre')->where('cierre', '>=', $desde)->selectRaw('user_id, COUNT(*) n, SUM(CASE WHEN diferencia <= -500 OR diferencia >= 500 THEN 1 ELSE 0 END) con_dif, COALESCE(SUM(CASE WHEN diferencia < 0 THEN -diferencia ELSE 0 END),0) faltante')->groupBy('user_id')->get()->keyBy('user_id');
        $deshora = (clone $ventas)->get(['user_id', 'emitido_en'])->filter(fn($c) => $c->emitido_en && ($c->emitido_en->hour < 7 || $c->emitido_en->hour >= 22 || $c->emitido_en->dayOfWeek === 0))->countBy('user_id');

        $filas = [];
        foreach ($ids as $uid) {
            $n = (int) $porUsuario[$uid]->n; $tot = (float) $porUsuario[$uid]->total;
            $filas[$uid] = ['id' => $uid, 'usuario' => $usuarios[$uid] ?? "Usuario {$uid}", 'ventas' => $n, 'total' => $tot,
                'anulaciones' => (int) ($anulados[$uid] ?? 0), 'anulaciones_pct' => $n ? round(($anulados[$uid] ?? 0) / $n * 100, 1) : 0,
                'descuentos' => (float) ($descuentos[$uid]->plata ?? 0), 'descuentos_pct' => $tot > 0 ? round((float) ($descuentos[$uid]->plata ?? 0) / $tot * 100, 1) : 0,
                'bajo_lista' => (int) ($bajoLista[$uid]->n ?? 0), 'bajo_lista_pct' => $n ? round(($bajoLista[$uid]->n ?? 0) / $n * 100, 1) : 0,
                'cajas' => (int) ($cajas[$uid]->n ?? 0), 'cajas_con_diferencia' => (int) ($cajas[$uid]->con_dif ?? 0), 'faltante' => (float) ($cajas[$uid]->faltante ?? 0),
                'deshora' => (int) ($deshora[$uid] ?? 0), 'deshora_pct' => $n ? round(($deshora[$uid] ?? 0) / $n * 100, 1) : 0];
        }
        $hallazgos = [];
        if (count($filas) >= 2) {
            $metricas = [
                'anulaciones_pct' => ['label' => 'anula', 'piso' => 3, 'abs' => 'anulaciones', 'fmt' => fn($f) => "{$f['anulaciones']} anulaciones sobre {$f['ventas']} ventas ({$f['anulaciones_pct']}%)"],
                'descuentos_pct' => ['label' => 'descuenta', 'piso' => 20000, 'abs' => 'descuentos', 'fmt' => fn($f) => 'descuentos por ' . AgentesService::pesos($f['descuentos']) . " ({$f['descuentos_pct']}% de lo que vendió)"],
                'bajo_lista_pct' => ['label' => 'vende bajo lista', 'piso' => 3, 'abs' => 'bajo_lista', 'fmt' => fn($f) => "{$f['bajo_lista']} renglones más de 30% abajo de la lista ({$f['bajo_lista_pct']}% de sus ventas)"],
                'cajas_con_diferencia' => ['label' => 'caja no cuadra', 'piso' => 3, 'abs' => 'cajas_con_diferencia', 'fmt' => fn($f) => "{$f['cajas_con_diferencia']} de {$f['cajas']} cierres con diferencia, faltante " . AgentesService::pesos($f['faltante'])],
                'deshora_pct' => ['label' => 'carga a deshora', 'piso' => 3, 'abs' => 'deshora', 'fmt' => fn($f) => "{$f['deshora']} ventas antes de las 7, después de las 22 o domingo ({$f['deshora_pct']}%)"],
            ];
            foreach ($filas as $uid => $f) {
                $otros = array_filter($filas, fn($x) => $x['id'] !== $uid); $motivos = [];
                foreach ($metricas as $k => $m) {
                    if ($f[$m['abs']] < $m['piso']) continue;
                    $prom = count($otros) ? array_sum(array_column($otros, $k)) / count($otros) : 0;
                    if ($f[$k] >= max($prom * self::FACTOR, $k === 'descuentos_pct' ? 1.0 : 0.5)) $motivos[] = ['que' => $m['label'], 'evidencia' => $m['fmt']($f), 'resto' => 'el resto del equipo: ' . round($prom, 1) . ($k === 'cajas_con_diferencia' ? '' : '%') . ' en promedio'];
                }
                if ($motivos) $hallazgos[] = ['id' => $uid, 'usuario' => $f['usuario'], 'motivos' => $motivos, 'severidad' => count($motivos) >= 2 || $f['faltante'] >= 20000 ? 'critica' : 'aviso'];
            }
        }
        return ['dias' => $dias, 'usuarios' => array_values($filas), 'hallazgos' => $hallazgos];
    }

    public function proponer(Business $b): array
    {
        $a = $this->analizar($b); $out = []; $semana = today()->format('o-W');
        foreach ($a['hallazgos'] as $h) {
            $out[] = ['clave' => "control-{$h['id']}-{$semana}", 'prioridad' => $h['severidad'] === 'critica' ? 1 : 2, 'accion' => null,
                'titulo' => "{$h['usuario']} se sale de lo normal: " . implode(', ', array_column($h['motivos'], 'que')),
                'detalle' => implode("\n", array_map(fn($m) => "• {$m['evidencia']} ({$m['resto']})", $h['motivos'])) . "\nÚltimos {$a['dias']} días, comparado con el resto del equipo. Mirá los comprobantes en Estadísticas → Qué hacer → Control de usuarios antes de hablar con la persona.",
                'datos' => ['usuario_id' => $h['id'], 'usuario' => $h['usuario'], 'motivos' => $h['motivos'], 'severidad' => $h['severidad']]];
            if ($h['severidad'] === 'critica') {
                Alerta::withoutGlobalScopes()->updateOrCreate(['business_id' => $b->id, 'tipo' => 'control_interno', 'modelo' => 'User', 'modelo_id' => $h['id'], 'resuelta_en' => null],
                    ['modulo' => 'estadisticas', 'severidad' => 'critica', 'titulo' => "Control interno: {$h['usuario']}", 'detalle' => implode('; ', array_column($h['motivos'], 'evidencia')), 'url' => '/propuestas']);
            }
        }
        return $out;
    }

    public function ejecutar(Propuesta $p, User $u): string { return 'Visto.'; }
}
