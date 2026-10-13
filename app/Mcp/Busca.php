<?php

namespace App\Mcp;

use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Product;
use App\Support\Sql;
use Illuminate\Support\Carbon;

// Búsquedas aproximadas y formatos que comparten las herramientas de IA.
class Busca
{
    public static function pesos($n): string { return '$ ' . number_format((float) $n, 2, ',', '.'); }
    public static function cant($n): float { return round((float) $n, 3); }
    public static function url(string $path): string { return rtrim(config('app.url'), '/') . $path; }

    // Contacto por id, CUIT exacto o nombre aproximado. $tipo: customers | suppliers.
    public static function contacto($texto, string $tipo = 'customers'): ?Contact
    {
        $t = trim((string) $texto); if ($t === '') return null;
        $q = fn() => Contact::$tipo()->where('is_active', true);
        if (ctype_digit($t) && strlen($t) < 9 && ($c = $q()->find((int) $t))) return $c;
        $dig = preg_replace('/\D/', '', $t);
        $like = Sql::like();
        if (strlen($dig) === 11 && ($c = $q()->get(['id', 'cuit'])->first(fn($x) => preg_replace('/\D/', '', (string) $x->cuit) === $dig))) return Contact::find($c->id);
        return $q()->where('name', $like, $t)->first()
            ?? $q()->where('name', $like, "%{$t}%")->orderByRaw('LENGTH(name)')->first()
            ?? $q()->where(function ($w) use ($t, $like) { foreach (array_filter(explode(' ', $t), fn($p) => mb_strlen($p) > 2) as $p) $w->where('name', $like, "%{$p}%"); })->first();
    }

    public static function cliente($t): ?Contact { return self::contacto($t, 'customers'); }
    public static function proveedor($t): ?Contact { return self::contacto($t, 'suppliers'); }

    public static function articulo($texto): ?Product
    {
        $t = trim((string) $texto); if ($t === '') return null;
        $like = Sql::like();
        if (ctype_digit($t) && strlen($t) < 7 && ($p = Product::where('active', true)->find((int) $t))) return $p;
        return Product::where('active', true)->where(fn($w) => $w->where('sku', $t)->orWhere('barcode', $t))->first()
            ?? Product::where('active', true)->where('name', $like, $t)->first()
            ?? Product::where('active', true)->where('name', $like, "%{$t}%")->orderByRaw('LENGTH(name)')->first()
            ?? Product::where('active', true)->where(function ($w) use ($t, $like) { foreach (array_filter(explode(' ', $t), fn($p) => mb_strlen($p) > 2) as $p) $w->where('name', $like, "%{$p}%"); })->orderByRaw('LENGTH(name)')->first();
    }

    // Comprobante por id o por número ("0001-00001234", "FA 1-1234", "1234").
    public static function comprobante($texto, ?string $direccion = 'venta'): ?Comprobante
    {
        $t = trim((string) $texto); if ($t === '') return null;
        $q = fn() => Comprobante::query()->when($direccion, fn($w) => $w->where('direccion', $direccion));
        if (preg_match('/^#?(\d+)$/', $t, $m) && ($c = $q()->find((int) $m[1]))) return $c;
        if (preg_match('/(\d{1,5})\s*-\s*(\d{1,8})/', $t, $m)) return $q()->where('punto_venta', (int) $m[1])->where('numero', (int) $m[2])->orderByDesc('id')->first();
        if (preg_match('/(\d{1,8})$/', $t, $m)) return $q()->where('numero', (int) $m[1])->orderByDesc('fecha')->first();
        return null;
    }

    public static function cuenta($texto, ?string $tipoDefault = 'caja'): ?CuentaFondos
    {
        $t = trim((string) $texto); $like = Sql::like();
        $q = fn() => CuentaFondos::where('activa', true);
        if ($t !== '' && ctype_digit($t) && ($c = $q()->find((int) $t))) return $c;
        if (in_array($t, ['caja', 'banco', 'billetera', 'tarjeta'], true)) return $q()->where('tipo', $t)->orderByDesc('es_default')->first();
        if ($t !== '' && ($c = $q()->where('nombre', $like, "%{$t}%")->first())) return $c;
        return $tipoDefault ? $q()->where('tipo', $tipoDefault)->orderByDesc('es_default')->first() : null;
    }

    // Período: hoy, ayer, semana, mes, mes_anterior, anio, o desde/hasta (YYYY-MM-DD).
    public static function periodo(array $a, string $default = 'mes'): array
    {
        if (! empty($a['desde'])) return [Carbon::parse($a['desde'])->startOfDay(), Carbon::parse($a['hasta'] ?? today())->endOfDay(), 'del ' . Carbon::parse($a['desde'])->format('d/m/Y') . ' al ' . Carbon::parse($a['hasta'] ?? today())->format('d/m/Y')];
        return match ($a['periodo'] ?? $default) {
            'hoy' => [today(), today()->endOfDay(), 'hoy'], 'ayer' => [today()->subDay(), today()->subDay()->endOfDay(), 'ayer'],
            'semana' => [today()->startOfWeek(), today()->endOfDay(), 'esta semana'], 'mes_anterior' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth(), 'el mes pasado'],
            'anio' => [today()->startOfYear(), today()->endOfDay(), 'este año'], default => [today()->startOfMonth(), today()->endOfDay(), 'este mes'],
        };
    }

    public const PERIODO = ['periodo' => ['type' => 'string', 'enum' => ['hoy', 'ayer', 'semana', 'mes', 'mes_anterior', 'anio'], 'description' => 'Período (o usá desde/hasta).'], 'desde' => ['type' => 'string', 'description' => 'Fecha desde, AAAA-MM-DD.'], 'hasta' => ['type' => 'string', 'description' => 'Fecha hasta, AAAA-MM-DD.']];

    public static function comp(Comprobante $c): array
    {
        return ['id' => $c->id, 'tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha?->toDateString(), 'vence' => $c->fecha_vto?->toDateString(), 'cliente' => $c->contact?->name ?? ($c->receptor['nombre'] ?? 'Consumidor final'),
            'estado' => $c->estado, 'total' => (float) $c->total, 'saldo' => (float) $c->saldo, 'cae' => $c->cae, 'url' => self::url(($c->direccion === 'compra' ? '/proveedores/compras/' : '/comprobantes/') . $c->id)];
    }
}
