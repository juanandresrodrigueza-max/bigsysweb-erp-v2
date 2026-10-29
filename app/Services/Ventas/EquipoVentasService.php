<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\AvisoVendedor;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\NotaCliente;
use App\Models\Relevamiento;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Fase 29.6: lo que ve cada uno. El vendedor: su reporte, comisión y objetivo con ritmo. El encargado: ranking, cumplimiento de ruta,
// dónde estuvieron, por qué no se vendió. Más avisos con vigencia, notas del cliente y relevamiento con fotos.
class EquipoVentasService
{
    public const PERIODOS = ['hoy' => 'Hoy', '7d' => '7 días', 'mes' => 'Este mes', 'mes_pasado' => 'Mes pasado'];
    private const FACTURAS = ['FA', 'FB', 'FC', 'FE'];
    private const CON_NC = ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'];

    public function periodo(?string $p): array
    {
        return match ($p) {
            '7d' => [today()->subDays(6), today()],
            'mes_pasado' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            'hoy' => [today(), today()],
            default => [today()->startOfMonth(), today()],
        };
    }

    private function ventasDe(?int $vendedorId, ?int $userId, Carbon $d, Carbon $h)
    {
        return Comprobante::ventas()->emitidos()->whereIn('tipo', self::CON_NC)->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])
            ->when($vendedorId, fn($q) => $q->where('vendedor_id', $vendedorId), fn($q) => $q->where('user_id', $userId));
    }

    private function resumen($ventas): array
    {
        $signo = fn(Comprobante $c) => $c->esNotaCredito() ? -1 : 1;
        $facturas = $ventas->filter(fn($c) => in_array($c->tipo, self::FACTURAS, true));
        $total = round($ventas->sum(fn($c) => $signo($c) * (float) $c->total), 2);
        return ['total' => $total, 'comprobantes' => $facturas->count(), 'clientes' => $facturas->pluck('contact_id')->unique()->count(), 'ticket' => $facturas->count() ? round($facturas->sum('total') / $facturas->count(), 2) : 0, 'devoluciones' => round($ventas->filter(fn($c) => $c->esNotaCredito())->sum('total'), 2)];
    }

    // ---- Mi reporte: el vendedor ve lo suyo y nada más ----
    public function reporteVendedor(User $u, ?string $p): array
    {
        [$d, $h] = $this->periodo($p);
        $v = Vendedor::deUsuario($u->id);
        $ventas = $this->ventasDe($v?->id, $u->id, $d, $h)->with('contact:id,name')->get();
        $hoy = $this->ventasDe($v?->id, $u->id, today(), today())->get();
        $mes = $this->ventasDe($v?->id, $u->id, today()->startOfMonth(), today())->get();
        $r = $this->resumen($ventas);
        // Comisión: el mismo criterio que la liquidación (total del comprobante menos notas de crédito; cobranza por separado).
        $cobros = Cobro::where('estado', '!=', 'anulado')->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->where(fn($q) => $v ? $q->where('cobrador_id', $v->id)->orWhere(fn($w) => $w->whereNull('cobrador_id')->where('vendedor_id', $v->id)) : $q->where('user_id', $u->id))->get();
        $cobrado = round($cobros->sum('total'), 2);
        // Cartera: a cuántos de sus clientes les vendió este mes y cuáles faltan.
        $cartera = $v ? Contact::customers()->where('is_active', true)->where('vendedor_id', $v->id)->orderBy('name')->get(['id', 'name', 'city']) : collect();
        $compraron = $mes->filter(fn($c) => in_array($c->tipo, self::FACTURAS, true))->pluck('contact_id')->unique();
        $visitas = Visita::where('user_id', $u->id)->whereBetween('created_at', [$d->copy()->startOfDay(), $h->copy()->endOfDay()])->get();
        $top = ComprobanteItem::whereIn('comprobante_id', $ventas->filter(fn($c) => in_array($c->tipo, self::FACTURAS, true))->pluck('id'))->whereNotNull('product_id')->selectRaw('product_id, descripcion, SUM(cantidad) c, SUM(total) t')->groupBy('product_id', 'descripcion')->orderByDesc('t')->limit(8)->get();
        return [
            'periodo' => ['k' => $p ?: 'mes', 'desde' => $d->toDateString(), 'hasta' => $h->toDateString(), 'opciones' => self::PERIODOS],
            'hoy' => $this->resumen($hoy),
            'periodo_resumen' => $r + ['visitas' => $visitas->count(), 'no_vendi' => $visitas->where('tipo', 'no_vendi')->count()],
            'mes' => $this->resumen($mes) + ['clientes_cartera' => $cartera->count(), 'compraron' => $compraron->count()],
            'objetivo' => $this->objetivo($v, $this->resumen($mes)['total']),
            'comision' => $v ? ['venta_pct' => (float) $v->comision_venta, 'cobro_pct' => (float) $v->comision_cobro, 'por_ventas' => round($r['total'] * (float) $v->comision_venta / 100, 2), 'por_cobros' => round($cobrado * (float) $v->comision_cobro / 100, 2), 'cobrado' => $cobrado, 'cobros' => $cobros->count()] : null,
            'faltan' => $cartera->filter(fn($c) => ! $compraron->contains($c->id))->values()->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name, 'ciudad' => $c->city])->all(),
            'compraron' => $cartera->filter(fn($c) => $compraron->contains($c->id))->values()->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name, 'total' => round($mes->where('contact_id', $c->id)->sum(fn($x) => ($x->esNotaCredito() ? -1 : 1) * (float) $x->total), 2)])->sortByDesc('total')->values()->all(),
            'top' => $top->map(fn($t) => ['product_id' => $t->product_id, 'nombre' => $t->descripcion, 'cantidad' => (float) $t->c, 'total' => (float) $t->t])->all(),
            'ultimas' => $ventas->sortByDesc('id')->take(15)->values()->map(fn($c) => ['id' => $c->id, 'fecha' => $c->fecha->format('d/m'), 'tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'total' => ($c->esNotaCredito() ? -1 : 1) * (float) $c->total])->all(),
        ];
    }

    // Objetivo del mes con ritmo: no es lo mismo llevar el 50 % el día 5 que el día 25.
    public function objetivo(?Vendedor $v, float $vendidoMes): ?array
    {
        if (! $v || (float) $v->objetivo_mensual <= 0) return null;
        $obj = (float) $v->objetivo_mensual; $dia = today()->day; $dias = today()->daysInMonth;
        $esperado = round($obj * $dia / $dias, 2);
        return ['objetivo' => $obj, 'vendido' => $vendidoMes, 'pct' => round(min(999, $vendidoMes / $obj * 100), 1), 'falta' => round(max(0, $obj - $vendidoMes), 2), 'esperado_hoy' => $esperado, 'ritmo' => $vendidoMes >= $esperado ? 'adelantado' : 'atrasado', 'diferencia' => round($vendidoMes - $esperado, 2), 'dia' => $dia, 'dias' => $dias];
    }

    // ---- El equipo, para el encargado ----
    public function equipo(?string $p): array
    {
        [$d, $h] = $this->periodo($p);
        $vendedores = Vendedor::where('activo', true)->orderBy('nombre')->get();
        $ventas = Comprobante::ventas()->emitidos()->whereIn('tipo', self::CON_NC)->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->whereNotNull('vendedor_id')->get();
        $visitas = Visita::whereBetween('created_at', [$d->copy()->startOfDay(), $h->copy()->endOfDay()])->with('contact:id,name,lat,lng', 'user:id,name')->orderByDesc('created_at')->get();
        $ranking = $vendedores->map(function ($v) use ($ventas, $visitas) {
            $mias = $ventas->where('vendedor_id', $v->id); $r = $this->resumen($mias);
            $vis = $visitas->where('vendedor_id', $v->id);
            $cartera = Contact::customers()->where('is_active', true)->where('vendedor_id', $v->id)->get(['id', 'name']);
            $visitados = $vis->pluck('contact_id')->unique(); $vendidos = $mias->filter(fn($c) => in_array($c->tipo, self::FACTURAS, true))->pluck('contact_id')->unique();
            return $r + ['id' => $v->id, 'nombre' => $v->nombre, 'modo' => $v->modo_default, 'objetivo' => $this->objetivo($v, $this->resumen($ventas->where('vendedor_id', $v->id)->filter(fn($c) => $c->fecha->isSameMonth(today())))['total']),
                'ruta' => ['asignados' => $cartera->count(), 'visitados' => $visitados->count(), 'vendidos' => $vendidos->count(), 'faltan' => $cartera->filter(fn($c) => ! $visitados->contains($c->id) && ! $vendidos->contains($c->id))->pluck('name')->values()->all()],
                'no_vendi' => $vis->where('tipo', 'no_vendi')->count()];
        })->sortByDesc('total')->values();
        // Cómo viene la venta: una barra por día.
        $porDia = []; for ($x = $d->copy(); $x <= $h; $x->addDay()) $porDia[$x->toDateString()] = 0.0;
        foreach ($ventas as $c) if (isset($porDia[$c->fecha->toDateString()])) $porDia[$c->fecha->toDateString()] += ($c->esNotaCredito() ? -1 : 1) * (float) $c->total;
        $motivos = $visitas->where('tipo', 'no_vendi')->groupBy('motivo')->map->count()->sortDesc()->mapWithKeys(fn($n, $k) => [Visita::MOTIVOS[$k] ?? ($k ?: 'sin motivo') => $n])->all();
        return [
            'periodo' => ['k' => $p ?: 'mes', 'desde' => $d->toDateString(), 'hasta' => $h->toDateString(), 'opciones' => self::PERIODOS],
            'ranking' => $ranking->all(),
            'por_dia' => collect($porDia)->map(fn($m, $f) => ['fecha' => Carbon::parse($f)->format('d/m'), 'monto' => round($m, 2)])->values()->all(),
            'motivos' => $motivos,
            'donde' => $visitas->take(150)->map(fn($vi) => ['cuando' => $vi->created_at->format('d/m H:i'), 'quien' => $vi->user?->name, 'cliente' => $vi->contact?->name, 'tipo' => ['llegue' => 'Llegó', 'no_vendi' => 'No vendió', 'venta' => 'Vendió', 'cobro' => 'Cobró'][$vi->tipo] ?? $vi->tipo, 'motivo' => $vi->motivo ? (Visita::MOTIVOS[$vi->motivo] ?? $vi->motivo) : null, 'lat' => $vi->lat, 'lng' => $vi->lng, 'distancia' => $this->distancia($vi)])->all(),
            'relevamientos' => Relevamiento::with('contact:id,name', 'user:id,name')->whereBetween('created_at', [$d->copy()->startOfDay(), $h->copy()->endOfDay()])->orderByDesc('id')->limit(60)->get()->map(fn($r) => ['id' => $r->id, 'cuando' => $r->created_at->format('d/m H:i'), 'cliente' => $r->contact?->name, 'contact_id' => $r->contact_id, 'quien' => $r->user?->name, 'tipo' => Relevamiento::TIPOS[$r->tipo] ?? $r->tipo, 'nota' => $r->nota, 'lat' => $r->lat, 'lng' => $r->lng])->all(),
        ];
    }

    // A cuántos metros del cliente quedó la visita (null si no hay posición de alguno de los dos). "Primera vez acá" cuando el cliente no tenía referencia.
    public function distancia(Visita $vi): ?array
    {
        if (! $vi->lat || ! $vi->lng) return null;
        if ($vi->distancia_m === null) return ['m' => null, 'texto' => 'primera vez acá'];
        $m = (int) $vi->distancia_m;
        return ['m' => $m, 'texto' => $m < 150 ? 'en el cliente' : ($m < 1000 ? "a {$m} m" : 'a ' . number_format($m / 1000, 1, ',', '.') . ' km')];
    }

    public static function metros(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000; $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round(2 * $r * asin(sqrt($a)));
    }

    // ---- Avisos ----
    public function avisosPara(?Vendedor $v): array
    {
        return AvisoVendedor::vigentes($v?->id)->orderByDesc('urgente')->orderByDesc('id')->get()->map(fn($a) => ['id' => $a->id, 'titulo' => $a->titulo, 'texto' => $a->texto, 'urgente' => $a->urgente, 'hasta' => $a->hasta?->format('d/m')])->all();
    }

    // ---- Notas del cliente ----
    public function notas(Contact $c, bool $todas = false): array
    {
        return NotaCliente::with('user:id,name')->where('contact_id', $c->id)->when(! $todas, fn($q) => $q->whereNull('listo_at'))->orderByDesc('id')->get()->map(fn($n) => ['id' => $n->id, 'texto' => trim(preg_replace('/\s*\[off-[^\]]+\]$/', '', $n->texto)), 'quien' => $n->user?->name, 'cuando' => $n->created_at->format('d/m/Y'), 'listo' => (bool) $n->listo_at])->all();
    }

    public function anotar(User $u, Contact $c, string $texto): NotaCliente
    {
        return NotaCliente::create(['business_id' => $u->business_id, 'contact_id' => $c->id, 'user_id' => $u->id, 'texto' => trim($texto), 'created_at' => now()]);
    }

    // ---- Relevamiento con fotos (necesita señal: las fotos no esperan en el teléfono) ----
    public function relevar(User $u, Contact $c, array $d): Relevamiento
    {
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $d['foto'] ?? '', $m)) throw ValidationException::withMessages(['foto' => 'La foto tiene que ser JPG, PNG o WEBP.']);
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 3 * 1024 * 1024) throw ValidationException::withMessages(['foto' => 'La foto es muy grande.']);
        $path = "relevamientos/{$u->business_id}/{$c->id}-" . now()->format('YmdHis') . '-' . substr(md5($bin), 0, 6) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        Storage::disk('local')->put($path, $bin);
        $r = Relevamiento::create(['business_id' => $u->business_id, 'contact_id' => $c->id, 'vendedor_id' => Vendedor::deUsuario($u->id)?->id, 'user_id' => $u->id, 'tipo' => $d['tipo'], 'nota' => $d['nota'] ?? null, 'foto' => $path, 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'created_at' => now()]);
        AuditLog::registrar('relevar', $r, "{$u->name} relevó " . (Relevamiento::TIPOS[$d['tipo']] ?? $d['tipo']) . " en {$c->name}");
        return $r;
    }
}
