<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Models\Rubro;
use App\Support\Sql;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 34: tocar cientos de precios y datos sin tocar uno por uno, viendo antes qué va a cambiar. Tres preguntas: qué hacer, a qué
// artículos y cómo. Todo o nada al grabar, con el valor de antes y después en el historial, y deshacer.
class PreciosMasivosService
{
    public const CAMPOS_DATOS = ['rubro_id' => 'Rubro', 'ubicacion' => 'Ubicación', 'iva' => 'IVA %', 'stock_min' => 'Stock mínimo', 'active' => 'Estado (activo)', 'moneda' => 'Moneda', 'marca' => 'Marca', 'proveedor_id' => 'Proveedor', 'controla_stock' => 'Controla stock', 'en_tienda' => 'En la tienda'];

    // ¿A qué artículos? Una selección a mano (ids) o un grupo: rubro (con sus hijos), proveedor, texto en nombre/código, comprados entre fechas, o todo.
    public function consulta(array $sel)
    {
        $q = Product::query()->where('active', true);
        if (! empty($sel['ids'])) return $q->whereIn('id', array_map('intval', $sel['ids']));
        $q->when($sel['rubro_id'] ?? null, fn($qq, $r) => $qq->whereIn('rubro_id', Rubro::conDescendientes((int) $r)))
            ->when($sel['proveedor_id'] ?? null, fn($qq, $p) => $qq->where('proveedor_id', $p))
            ->when($sel['marca'] ?? null, fn($qq, $m) => $qq->where('marca', Sql::like(), "%{$m}%"))
            ->when(trim((string) ($sel['texto'] ?? '')) !== '', fn($qq) => $qq->where(fn($w) => $w->where('name', Sql::like(), '%' . trim($sel['texto']) . '%')->orWhere('sku', Sql::like(), '%' . trim($sel['texto']) . '%')->orWhere('description', Sql::like(), '%' . trim($sel['texto']) . '%')))
            ->when(($sel['moneda'] ?? '') !== '', fn($qq) => $qq->where('moneda', $sel['moneda']));
        if (! empty($sel['comprados_desde']) || ! empty($sel['comprados_hasta'])) {
            $ids = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', Auth::user()->business_id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')
                ->when($sel['comprados_desde'] ?? null, fn($qq, $d) => $qq->where('comprobantes.fecha', '>=', $d))->when($sel['comprados_hasta'] ?? null, fn($qq, $h) => $qq->where('comprobantes.fecha', '<=', $h))->whereNotNull('comprobante_items.product_id')->distinct()->pluck('comprobante_items.product_id');
            $q->whereIn('id', $ids);
        }
        return $q;
    }

    // ¿Cómo? campo: cost | 1..6 (lista). modo: porcentaje | margen | exacto | monto | sobre_lista (lista base + %). redondeo: 0, 1, 10, 100.
    // Devuelve los cambios [campo => valor] o [] si no cambia nada. Nunca toca lo que no se pidió.
    public function calcular(Product $p, array $como): array
    {
        $red = (int) ($como['redondeo'] ?? 0); $r = fn($v) => $red ? round($v / $red) * $red : round($v, 2);
        $campo = (string) ($como['campo'] ?? '1'); $modo = $como['modo'] ?? 'porcentaje'; $valor = (float) ($como['valor'] ?? 0);
        $actual = $campo === 'cost' ? (float) $p->cost : $p->precioListaCruda((int) $campo);
        $nuevo = match ($modo) {
            'porcentaje' => $actual * (1 + $valor / 100),
            'margen' => (float) $p->cost * (1 + $valor / 100),
            'exacto' => $valor,
            'monto' => $actual + $valor,
            'sobre_lista' => $p->precioListaCruda((int) ($como['lista_base'] ?? 1)) * (1 + $valor / 100),
            default => $actual,
        };
        if ($modo === 'margen' && (float) $p->cost <= 0) return [];
        $nuevo = $r(max(0, $nuevo));
        if (abs($nuevo - $actual) < 0.005 && $modo !== 'exacto') return [];
        if ($campo === 'cost') return ['cost' => $nuevo];
        if ((int) $campo <= 1) return ['price' => $nuevo];
        $prices = $p->prices ?? []; $prices[(string) (int) $campo] = $nuevo;
        return ['prices' => $prices];
    }

    public function previsualizar(array $sel, array $como, int $limite = 500): array
    {
        $out = []; $n = 0; $avisos = ['bajo_costo' => 0, 'cero' => 0, 'mucho' => 0]; $limitePct = (float) ($como['limite_pct'] ?? 50);
        foreach ($this->consulta($sel)->orderBy('name')->get() as $p) {
            $upd = $this->calcular($p, $como); if (! $upd) continue; $n++;
            $campo = (string) ($como['campo'] ?? '1');
            $antes = $campo === 'cost' ? (float) $p->cost : $p->precioListaCruda((int) $campo);
            $despues = $campo === 'cost' ? $upd['cost'] : ((int) $campo <= 1 ? $upd['price'] : $upd['prices'][(string) (int) $campo]);
            $var = $antes > 0 ? round(($despues - $antes) / $antes * 100, 1) : null;
            $costo = $campo === 'cost' ? $despues : (float) $p->cost;
            $alerta = $despues <= 0 ? 'cero' : ($campo !== 'cost' && $costo > 0 && $despues < $costo ? 'bajo_costo' : ($var !== null && abs($var) > $limitePct ? 'mucho' : null));
            if ($alerta) $avisos[$alerta]++;
            if (count($out) < $limite) $out[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'moneda' => $p->moneda ?: 'ARS', 'antes' => $antes, 'despues' => $despues, 'var' => $var, 'costo' => (float) $p->cost, 'alerta' => $alerta, 'incluir' => $alerta === null];
        }
        $moneda = (int) ($como['campo'] ?? 1) > 0 && ($como['modo'] ?? '') === 'exacto' ? collect($out)->pluck('moneda')->unique()->count() > 1 : false;
        return ['n' => $n, 'filas' => $out, 'avisos' => $avisos, 'mezcla_monedas' => $moneda, 'limite_pct' => $limitePct];
    }

    // Aplica. $excluir: ids que el usuario destildó. Todo o nada: si un artículo falla, no se graba ninguno. Queda el antes y después para deshacer.
    public function aplicar(array $sel, array $como, array $excluir = []): AuditLog
    {
        $antes = []; $despues = []; $n = 0;
        DB::transaction(function () use ($sel, $como, $excluir, &$antes, &$despues, &$n) {
            foreach ($this->consulta($sel)->lockForUpdate()->get() as $p) {
                if (in_array($p->id, $excluir)) continue;
                $upd = $this->calcular($p, $como); if (! $upd) continue;
                $antes[$p->id] = ['price' => (float) $p->price, 'prices' => $p->prices, 'cost' => (float) $p->cost, 'moneda' => $p->moneda, 'nombre' => $p->name];
                if (($como['modo'] ?? '') === 'exacto' && ! empty($como['moneda']) && in_array($como['moneda'], ['ARS', 'USD'], true)) $upd['moneda'] = $como['moneda'];
                if (isset($upd['cost']) && ! empty($como['recalcular_listas'])) { $p->forceFill($upd); $p->recalcularDesdeCosto(); $upd = ['cost' => (float) $p->cost, 'price' => (float) $p->price, 'prices' => $p->prices]; }
                if (isset($upd['price']) || isset($upd['prices'])) $upd['precio_actualizado_en'] = now();
                $p->forceFill($upd)->save(); $n++;
                $despues[$p->id] = ['price' => (float) $p->price, 'prices' => $p->prices, 'cost' => (float) $p->cost, 'moneda' => $p->moneda];
            }
            if ($n === 0) throw ValidationException::withMessages(['como' => 'Con esos parámetros no cambia ningún artículo.']);
        });
        $desc = $this->describir($como, $sel, $n);
        return AuditLog::registrar('precios_masivo', null, $desc, $antes, ['cambios' => $despues, 'como' => $como, 'seleccion' => collect($sel)->except('ids')->filter()->all(), 'n' => $n]);
    }

    private function describir(array $como, array $sel, int $n): string
    {
        $campo = (string) ($como['campo'] ?? '1'); $que = $campo === 'cost' ? 'el costo' : "la lista {$campo}";
        $v = (float) ($como['valor'] ?? 0);
        $accion = match ($como['modo'] ?? 'porcentaje') { 'porcentaje' => ($v >= 0 ? 'subió' : 'bajó') . ' ' . abs($v) . '% ' . $que, 'margen' => "puso {$que} con {$v}% de margen sobre el costo", 'exacto' => "puso {$que} en " . number_format($v, 2, ',', '.'), 'monto' => ($v >= 0 ? 'sumó' : 'restó') . ' $ ' . number_format(abs($v), 2, ',', '.') . " a {$que}", 'sobre_lista' => "puso {$que} = lista " . ($como['lista_base'] ?? 1) . " " . ($v >= 0 ? '+' : '') . "{$v}%", default => "cambió {$que}" };
        $grupo = ! empty($sel['ids']) ? count($sel['ids']) . ' artículos elegidos a mano' : (($sel['rubro_id'] ?? null) ? 'el rubro ' . (Rubro::find($sel['rubro_id'])?->nombre ?? $sel['rubro_id']) : (($sel['proveedor_id'] ?? null) ? 'el proveedor ' . (\App\Models\Contact::find($sel['proveedor_id'])?->name ?? '') : (trim((string) ($sel['texto'] ?? '')) !== '' ? "los que dicen «{$sel['texto']}»" : 'todo el catálogo')));
        return ucfirst("{$accion} en {$grupo} ({$n} artículos)");
    }

    // Deshacer una actualización por su id de auditoría (la última, o una puntual, mientras tenga el antes y menos de 30 días).
    public function deshacer(?int $logId = null): int
    {
        $log = $logId ? AuditLog::whereIn('accion', ['precios_masivo', 'datos_masivo'])->findOrFail($logId) : AuditLog::whereIn('accion', ['precios_masivo', 'datos_masivo'])->whereNotNull('antes')->latest('id')->first();
        abort_if(! $log || ! $log->antes || $log->created_at->lt(now()->subDays(30)), 422, 'No hay nada reciente para deshacer (o ya se deshizo).');
        $n = 0;
        DB::transaction(function () use ($log, &$n) {
            foreach ($log->antes as $id => $v) { if ($p = Product::withoutGlobalScopes()->whereKey($id)->where('business_id', $log->business_id)->first()) { $p->forceFill(collect($v)->except('nombre')->all())->save(); $n++; } }
            $log->update(['antes' => null]);
        });
        AuditLog::registrar('editar', null, "Deshizo «{$log->descripcion}»: {$n} artículos vuelven a los valores anteriores");
        return $n;
    }

    // ---- Cambiar datos en masa (34.5): rubro, ubicación, IVA, stock mínimo, estado, moneda, marca, proveedor… para una selección ----
    public function previsualizarDatos(array $sel, array $cambios, int $limite = 300): array
    {
        $cambios = $this->limpiarCambios($cambios);
        $out = []; $n = 0;
        foreach ($this->consulta($sel)->orderBy('name')->get() as $p) {
            $dif = [];
            foreach ($cambios as $k => $v) if ((string) $p->{$k} !== (string) $v) $dif[$k] = ['antes' => $p->{$k}, 'despues' => $v];
            if (! $dif) continue; $n++;
            if (count($out) < $limite) $out[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'cambios' => $dif];
        }
        return ['n' => $n, 'filas' => $out, 'cambios' => $cambios];
    }

    public function aplicarDatos(array $sel, array $cambios, array $excluir = []): AuditLog
    {
        $cambios = $this->limpiarCambios($cambios);
        if (! $cambios) throw ValidationException::withMessages(['cambios' => 'Elegí qué dato cambiar.']);
        $antes = []; $n = 0;
        DB::transaction(function () use ($sel, $cambios, $excluir, &$antes, &$n) {
            foreach ($this->consulta($sel)->lockForUpdate()->get() as $p) {
                if (in_array($p->id, $excluir)) continue;
                $dif = array_filter($cambios, fn($v, $k) => (string) $p->{$k} !== (string) $v, ARRAY_FILTER_USE_BOTH); if (! $dif) continue;
                $antes[$p->id] = collect(array_keys($dif))->mapWithKeys(fn($k) => [$k => $p->{$k}])->all() + ['nombre' => $p->name];
                $p->forceFill($dif)->save(); $n++;
            }
            if ($n === 0) throw ValidationException::withMessages(['cambios' => 'Ningún artículo cambia con esos datos.']);
        });
        $que = collect($cambios)->map(fn($v, $k) => (self::CAMPOS_DATOS[$k] ?? $k) . ' → ' . ($k === 'rubro_id' ? (Rubro::find($v)?->nombre ?? $v) : ($k === 'proveedor_id' ? (\App\Models\Contact::find($v)?->name ?? $v) : (is_bool($v) ? ($v ? 'sí' : 'no') : $v))))->implode(', ');
        return AuditLog::registrar('datos_masivo', null, "Cambió datos en masa: {$que} ({$n} artículos)", $antes, ['cambios' => $cambios, 'n' => $n]);
    }

    private function limpiarCambios(array $c): array
    {
        $out = [];
        foreach ($c as $k => $v) {
            if (! isset(self::CAMPOS_DATOS[$k]) || $v === null || $v === '') continue;
            $out[$k] = match ($k) { 'active', 'controla_stock', 'en_tienda' => filter_var($v, FILTER_VALIDATE_BOOLEAN), 'iva', 'stock_min' => (float) $v, 'rubro_id', 'proveedor_id' => (int) $v, 'moneda' => in_array($v, ['ARS', 'USD'], true) ? $v : 'ARS', default => trim((string) $v) };
        }
        return $out;
    }

    // Historial: quién, cuándo, a qué artículos y el valor de antes y después.
    public function historial(int $limite = 40): array
    {
        return AuditLog::whereIn('accion', ['precios_masivo', 'datos_masivo'])->with('user:id,name')->latest('id')->limit($limite)->get()->map(function ($l) {
            $antes = $l->antes ?? []; $desp = $l->despues['cambios'] ?? [];
            $muestra = collect($antes)->take(8)->map(fn($a, $id) => ['id' => (int) $id, 'nombre' => $a['nombre'] ?? "#{$id}", 'antes' => collect($a)->except('nombre')->all(), 'despues' => $desp[$id] ?? ($l->despues['cambios'] ?? null)])->values()->all();
            return ['id' => $l->id, 'fecha' => $l->created_at->format('d/m/Y H:i'), 'usuario' => $l->user?->name ?? '—', 'descripcion' => $l->descripcion, 'n' => (int) ($l->despues['n'] ?? count($antes)), 'se_puede_deshacer' => (bool) $l->antes && $l->created_at->gt(now()->subDays(30)), 'tipo' => $l->accion, 'muestra' => $muestra];
        })->all();
    }
}
