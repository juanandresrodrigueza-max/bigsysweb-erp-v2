<?php

namespace App\Services\Ventas;

use App\Models\Acopio;
use App\Models\AuditLog;
use App\Models\BusinessLocation;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaItem;
use App\Services\Comprobantes\AcopioService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 37.1 a 37.3: el viaje del camión. Lo pendiente por cliente (facturas, remitos, acopios) con cantidades parciales y paradas a mano;
// la planilla de carga sumada por artículo; el recorrido más corto desde el depósito y Google Maps de a 4 paradas; el link del chofer
// (salí, entregué con nombre y foto, no se pudo con motivo, su posición) y el del cliente (estado, por dónde viene, a cuántos km está).
class RepartoService
{
    public function __construct(private EntregasService $entregas) {}

    // Lo que está en una hoja abierta y no volvió como "no se pudo".
    private function enViaje(string $campo): array
    {
        return OrdenEntregaItem::whereHas('hoja', fn($q) => $q->whereIn('estado', ['pendiente', 'en_curso']))->where('estado', '!=', 'no_entregado')->whereNotNull($campo)->pluck($campo)->all();
    }

    // 37.1: lo pendiente de entregar, agrupado por cliente.
    public function pendientes(): array
    {
        $ocupadosC = $this->enViaje('comprobante_id'); $ocupadosA = $this->enViaje('acopio_id');
        $comps = Comprobante::ventas()->emitidos()->with(['items', 'contact:id,name,address,city,lat,lng,phone,mobile'])
            ->where(fn($q) => $q->where(fn($w) => $w->facturas()->where('entrega_pendiente', true))->orWhere(fn($w) => $w->where('tipo', 'REM')->where('fecha', '>=', today()->subDays(60))))
            ->whereNotIn('id', $ocupadosC)->orderBy('fecha')->get()
            ->filter(fn($c) => $c->tipo === 'REM' ? ! OrdenEntregaItem::where('comprobante_id', $c->id)->where('estado', 'entregado')->exists() : $c->pendienteEntrega() > 0);
        $acopios = Acopio::with(['items', 'contact:id,name,address,city,lat,lng,phone,mobile'])->whereIn('estado', ['abierto', 'parcial', 'vencido'])->whereNotIn('id', $ocupadosA)->get();
        $porCliente = [];
        $agregar = function ($contact, array $doc) use (&$porCliente) {
            $k = $contact?->id ?? 0;
            $porCliente[$k] ??= ['contact_id' => $contact?->id, 'cliente' => $contact?->name ?? 'Sin cliente', 'direccion' => trim(($contact?->address ?? '') . ' ' . ($contact?->city ?? '')), 'con_ubicacion' => $contact?->lat !== null, 'documentos' => []];
            $porCliente[$k]['documentos'][] = $doc;
        };
        foreach ($comps as $c) {
            $remito = $c->tipo === 'REM';
            $agregar($c->contact, ['tipo' => 'comprobante', 'id' => $c->id, 'label' => "{$c->nombreTipo()} {$c->numeroFormateado()}", 'fecha' => $c->fecha->format('d/m'), 'total' => (float) $c->total,
                'items' => $c->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'unidad' => $i->unidad, 'pendiente' => $remito ? (float) $i->cantidad : round((float) $i->cantidad - (float) $i->cantidad_entregada, 3)])->filter(fn($i) => $i['pendiente'] > 0)->values()->all()]);
        }
        foreach ($acopios as $a) {
            $items = $a->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'unidad' => null, 'pendiente' => $i->pendiente()])->filter(fn($i) => $i['pendiente'] > 0)->values()->all();
            if ($items) $agregar($a->contact, ['tipo' => 'acopio', 'id' => $a->id, 'label' => "Retiro de acopio #{$a->id}", 'fecha' => $a->fecha?->format('d/m'), 'total' => null, 'items' => $items]);
        }
        return collect($porCliente)->sortBy('cliente')->values()->all();
    }

    // Armar el viaje: paradas [{tipo, id, cantidades?: {item_id: cant}}] o [{tipo: parada, nombre, direccion}].
    public function armar(array $d): OrdenEntrega
    {
        $paradas = array_values($d['paradas'] ?? []);
        if (! $paradas) throw ValidationException::withMessages(['paradas' => 'Elegí al menos una entrega para el viaje.']);
        return DB::transaction(function () use ($d, $paradas) {
            $u = Auth::user(); $loc = BusinessLocation::find($u->current_location_id);
            $oe = OrdenEntrega::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'user_id' => $u->id, 'numero' => ((int) OrdenEntrega::withoutGlobalScopes()->where('business_id', $u->business_id)->max('numero')) + 1, 'fecha' => $d['fecha'] ?? today(), 'repartidor' => $d['repartidor'] ?? null, 'vehiculo' => $d['vehiculo'] ?? null, 'notas' => $d['notas'] ?? null, 'estado' => 'pendiente', 'token_chofer' => Str::random(40), 'origen_lat' => $loc?->lat, 'origen_lng' => $loc?->lng]);
            foreach ($paradas as $i => $p) {
                $base = ['orden' => $i, 'tipo' => $p['tipo'], 'token' => Str::random(32), 'cantidades' => ! empty($p['cantidades']) ? array_map('floatval', array_filter($p['cantidades'], fn($v) => (float) $v > 0)) : null];
                if ($p['tipo'] === 'comprobante') {
                    $c = Comprobante::ventas()->emitidos()->with('contact', 'items')->findOrFail($p['id']);
                    foreach ($base['cantidades'] ?? [] as $itemId => $cant) { $it = $c->items->firstWhere('id', (int) $itemId); abort_if(! $it, 422, 'Ítem inválido.'); $pend = $c->tipo === 'REM' ? (float) $it->cantidad : (float) $it->cantidad - (float) $it->cantidad_entregada; if ($cant > $pend + 0.0005) throw ValidationException::withMessages(['paradas' => "{$it->descripcion}: quedan {$pend}, no {$cant}."]); }
                    $oe->items()->create($base + ['comprobante_id' => $c->id, 'contact_id' => $c->contact_id, 'direccion' => $p['direccion'] ?? (trim(($c->contact?->address ?? '') . ' ' . ($c->contact?->city ?? '')) ?: null), 'lat' => $c->contact?->lat, 'lng' => $c->contact?->lng]);
                } elseif ($p['tipo'] === 'acopio') {
                    $a = Acopio::with('contact', 'items')->findOrFail($p['id']);
                    foreach ($base['cantidades'] ?? [] as $itemId => $cant) { $it = $a->items->firstWhere('id', (int) $itemId); abort_if(! $it, 422, 'Ítem inválido.'); if ($cant > $it->pendiente() + 0.0005) throw ValidationException::withMessages(['paradas' => "{$it->descripcion}: quedan {$it->pendiente()} por retirar."]); }
                    $oe->items()->create($base + ['acopio_id' => $a->id, 'contact_id' => $a->contact_id, 'direccion' => trim(($a->contact?->address ?? '') . ' ' . ($a->contact?->city ?? '')) ?: null, 'lat' => $a->contact?->lat, 'lng' => $a->contact?->lng]);
                } else {
                    $c = ! empty($p['contact_id']) ? Contact::find($p['contact_id']) : null;
                    $oe->items()->create($base + ['nombre' => $p['nombre'] ?? $c?->name ?? 'Parada', 'contact_id' => $c?->id, 'direccion' => $p['direccion'] ?? null, 'lat' => $p['lat'] ?? $c?->lat, 'lng' => $p['lng'] ?? $c?->lng]);
                }
            }
            if (! empty($d['ordenar'])) $this->ordenar($oe);
            AuditLog::registrar('crear', $oe, "Viaje {$oe->numeroFormateado()} con " . count($paradas) . ' paradas');
            return $oe->fresh('items');
        });
    }

    // Qué lleva cada parada (lo elegido, o todo lo pendiente).
    public function lleva(OrdenEntregaItem $it): array
    {
        $cant = $it->cantidades ?? [];
        if ($it->tipo === 'comprobante' && $it->comprobante) return $it->comprobante->items->map(fn($i) => ['product_id' => $i->product_id, 'item_id' => $i->id, 'descripcion' => $i->descripcion, 'unidad' => $i->unidad, 'cantidad' => $cant ? (float) ($cant[$i->id] ?? $cant[(string) $i->id] ?? 0) : ($it->comprobante->tipo === 'REM' ? (float) $i->cantidad : round((float) $i->cantidad - (float) $i->cantidad_entregada, 3))])->filter(fn($x) => $x['cantidad'] > 0)->values()->all();
        if ($it->tipo === 'acopio' && $it->acopio) return $it->acopio->items->map(fn($i) => ['product_id' => $i->product_id, 'item_id' => $i->id, 'descripcion' => $i->descripcion, 'unidad' => null, 'cantidad' => $cant ? (float) ($cant[$i->id] ?? $cant[(string) $i->id] ?? 0) : $i->pendiente()])->filter(fn($x) => $x['cantidad'] > 0)->values()->all();
        return [];
    }

    // La planilla de carga: todo lo que sube al camión, sumado por artículo.
    public function planillaCarga(OrdenEntrega $oe): array
    {
        $tot = [];
        foreach ($oe->items()->with('comprobante.items', 'acopio.items')->get() as $it) foreach ($this->lleva($it) as $x) {
            $k = $x['product_id'] ?: 'd:' . mb_strtolower($x['descripcion']);
            $tot[$k] ??= ['descripcion' => $x['descripcion'], 'unidad' => $x['unidad'], 'cantidad' => 0, 'paradas' => 0];
            $tot[$k]['cantidad'] += $x['cantidad']; $tot[$k]['paradas']++;
        }
        return collect($tot)->map(fn($t) => $t + ['cantidad' => round($t['cantidad'], 3)])->sortBy('descripcion')->values()->all();
    }

    public static function km(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
        $r = 6371; $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    // 37.2: el orden más corto desde el depósito (vecino más cercano y después mejora por pares). Las paradas sin ubicación van al final.
    public function ordenar(OrdenEntrega $oe): array
    {
        $items = $oe->items()->get(); $con = $items->filter(fn($i) => $i->lat !== null && $i->lng !== null)->values(); $sin = $items->filter(fn($i) => $i->lat === null || $i->lng === null)->values();
        $pos = [$oe->origen_lat, $oe->origen_lng]; $ruta = []; $quedan = $con->all();
        if ($pos[0] === null && $quedan) { $p = array_shift($quedan); $ruta[] = $p; $pos = [$p->lat, $p->lng]; }
        while ($quedan) { usort($quedan, fn($a, $b) => self::km($pos[0], $pos[1], $a->lat, $a->lng) <=> self::km($pos[0], $pos[1], $b->lat, $b->lng)); $p = array_shift($quedan); $ruta[] = $p; $pos = [$p->lat, $p->lng]; }
        $largo = function ($r) use ($oe) { $t = 0; $prev = [$oe->origen_lat, $oe->origen_lng]; foreach ($r as $p) { if ($prev[0] !== null) $t += self::km($prev[0], $prev[1], $p->lat, $p->lng); $prev = [$p->lat, $p->lng]; } return $t; };
        $mejoro = true; $n = count($ruta); $vueltas = 0;
        while ($mejoro && $vueltas++ < 50) { $mejoro = false; for ($i = 0; $i < $n - 1; $i++) for ($j = $i + 1; $j < $n; $j++) { $nueva = array_merge(array_slice($ruta, 0, $i), array_reverse(array_slice($ruta, $i, $j - $i + 1)), array_slice($ruta, $j + 1)); if ($largo($nueva) + 0.001 < $largo($ruta)) { $ruta = $nueva; $mejoro = true; } } }
        foreach (array_merge($ruta, $sin->all()) as $k => $it) $it->update(['orden' => $k]);
        return ['km' => round($largo($ruta), 1), 'sin_ubicacion' => $sin->count()];
    }

    public function reordenar(OrdenEntrega $oe, array $ids): void
    {
        foreach (array_values($ids) as $k => $id) $oe->items()->where('id', (int) $id)->update(['orden' => $k]);
    }

    // Google Maps de a 4 paradas por tramo (lo que se maneja bien desde el celular).
    public function maps(OrdenEntrega $oe): array
    {
        $pts = $oe->items()->get()->filter(fn($i) => $i->estado !== 'entregado')->map(fn($i) => $i->lat !== null ? "{$i->lat},{$i->lng}" : ($i->direccion ? rawurlencode($i->direccion) : null))->filter()->values()->all();
        $origen = $oe->origen_lat !== null ? "{$oe->origen_lat},{$oe->origen_lng}" : null; $out = [];
        foreach (array_chunk($pts, 4) as $k => $tramo) {
            $dest = array_pop($tramo);
            $out[] = 'https://www.google.com/maps/dir/?api=1' . ($origen ? "&origin={$origen}" : '') . "&destination={$dest}" . ($tramo ? '&waypoints=' . implode('%7C', $tramo) : '') . '&travelmode=driving';
            $origen = $dest;
        }
        return $out;
    }

    // ---- 37.3 El chofer ----
    public function salir(OrdenEntrega $oe): void
    {
        abort_if(in_array($oe->estado, ['entregada', 'cancelada'], true), 422, 'El viaje está cerrado.');
        $oe->update(['estado' => 'en_curso', 'salida_en' => $oe->salida_en ?? now()]);
    }

    public function posicion(OrdenEntrega $oe, float $lat, float $lng): void
    {
        $oe->update(['ultima_lat' => $lat, 'ultima_lng' => $lng, 'ultima_pos_en' => now()]);
    }

    // Entregué: con quién lo recibió y la foto. Mueve el stock (remito por lo que va) o arma el retiro del acopio, y aprende dónde queda el cliente.
    public function entregue(OrdenEntregaItem $it, ?string $recibio, ?string $fotoPath, ?float $lat, ?float $lng): void
    {
        abort_if($it->estado === 'entregado', 422, 'Esta parada ya se entregó.');
        DB::transaction(function () use ($it, $recibio, $fotoPath, $lat, $lng) {
            $lleva = $this->lleva($it);
            if ($it->tipo === 'comprobante' && $it->comprobante && $it->comprobante->tipo !== 'REM' && $it->comprobante->pendienteEntrega() > 0)
                $this->entregas->convertirParcial($it->comprobante, 'REM', collect($lleva)->mapWithKeys(fn($x) => [$x['item_id'] => $x['cantidad']])->all());
            if ($it->tipo === 'acopio' && $it->acopio)
                app(AcopioService::class)->retirar($it->acopio, ['fecha' => today()->toDateString(), 'retirado_por' => $recibio, 'observaciones' => "Entregado en el viaje {$it->hoja->numeroFormateado()}", 'items' => collect($lleva)->map(fn($x) => ['acopio_item_id' => $x['item_id'], 'cantidad' => $x['cantidad']])->all()]);
            $it->update(['estado' => 'entregado', 'entregado_en' => now(), 'recibio' => $recibio, 'foto_path' => $fotoPath, 'motivo' => null] + ($lat !== null ? ['lat' => $it->lat ?? $lat, 'lng' => $it->lng ?? $lng] : []));
            // Aprender la ubicación del cliente: la primera vez se guarda; después se promedia si está cerca (menos de 300 m).
            if ($lat !== null && $it->contact_id && ($c = Contact::find($it->contact_id))) {
                if ($c->lat === null) $c->update(['lat' => $lat, 'lng' => $lng]);
                elseif (self::km($c->lat, $c->lng, $lat, $lng) < 0.3) $c->update(['lat' => round(($c->lat + $lat) / 2, 7), 'lng' => round(($c->lng + $lng) / 2, 7)]);
            }
            $this->cerrarSiTermino($it->hoja);
        });
    }

    // No se pudo: con motivo. La parada vuelve a pendientes para el próximo viaje.
    public function noSePudo(OrdenEntregaItem $it, string $motivo): void
    {
        $it->update(['estado' => 'no_entregado', 'motivo' => mb_substr($motivo, 0, 200), 'observacion' => $motivo]);
        $this->cerrarSiTermino($it->hoja);
    }

    private function cerrarSiTermino(OrdenEntrega $oe): void
    {
        if ($oe->items()->where('estado', 'pendiente')->doesntExist()) $oe->update(['estado' => 'entregada', 'entregada_en' => now()]);
        elseif ($oe->estado === 'pendiente') $oe->update(['estado' => 'en_curso']);
    }

    // ---- El cliente ----
    public function seguimiento(OrdenEntregaItem $it): array
    {
        $oe = $it->hoja; $antes = $oe->items()->where('orden', '<', $it->orden)->where('estado', 'pendiente')->count();
        $km = self::km($oe->ultima_lat, $oe->ultima_lng, $it->lat, $it->lng);
        $estado = match (true) { $it->estado === 'entregado' => 'entregado', $it->estado === 'no_entregado' => 'no_se_pudo', $oe->estado === 'en_curso' => 'en_camino', default => 'preparando' };
        return ['estado' => $estado, 'paradas_antes' => $antes, 'km' => $km, 'posicion' => $oe->ultima_lat !== null && $estado === 'en_camino' ? ['lat' => $oe->ultima_lat, 'lng' => $oe->ultima_lng, 'hace' => $oe->ultima_pos_en?->diffForHumans()] : null, 'recibio' => $it->recibio, 'entregado' => $it->entregado_en?->format('d/m H:i'), 'motivo' => $it->motivo, 'repartidor' => $oe->repartidor, 'empresa' => $oe->business?->name ?? \App\Models\Business::find($oe->business_id)?->name];
    }
}
