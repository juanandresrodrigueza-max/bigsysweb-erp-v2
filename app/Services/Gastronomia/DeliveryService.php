<?php

namespace App\Services\Gastronomia;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comanda;
use App\Services\Canales\WhatsappPedidosService;
use Illuminate\Support\Str;

// Fase 91: delivery propio: zonas con costo de envío, el pedido sale al reparto en un toque y se cobra al entregar.
class DeliveryService
{
    public const MEDIOS = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'mercadopago' => 'MP / QR', 'transferencia' => 'Transferencia'];

    public static function zonas(Business $b): array
    {
        return array_values(array_filter((array) ($b->gastronomia_config['zonas'] ?? []), fn($z) => ! empty($z['nombre'])));
    }

    public static function repartidores(Business $b): array
    {
        return array_values(array_filter(array_map('trim', (array) ($b->gastronomia_config['repartidores'] ?? []))));
    }

    public static function zona(Business $b, ?string $nombre): ?array
    {
        if (! $nombre) return null;
        foreach (self::zonas($b) as $z) if (Str::lower(Str::ascii($z['nombre'])) === Str::lower(Str::ascii($nombre))) return $z;
        return null;
    }

    // "Alberdi 1234, barrio Güemes" → la zona cuyas palabras (barrios, calles) aparecen en la dirección.
    public static function detectar(Business $b, ?string $direccion): ?array
    {
        if (! $direccion) return null;
        $d = ' ' . Str::lower(Str::ascii($direccion)) . ' ';
        foreach (self::zonas($b) as $z) {
            foreach (preg_split('/[,;\n]+/', (string) ($z['palabras'] ?? '')) as $p) { $p = trim(Str::lower(Str::ascii($p))); if ($p !== '' && str_contains($d, $p)) return $z; }
            if (str_contains($d, Str::lower(Str::ascii($z['nombre'])))) return $z;
        }
        return null;
    }

    // Pone (o cambia) el envío de la comanda como un renglón más: así se cobra y factura con lo demás.
    public function aplicarZona(Comanda $c, ?array $zona): void
    {
        $c->items()->whereNull('product_id')->where('descripcion', 'like', 'Envío%')->delete();
        if ($zona) {
            $b = $c->business; $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
            if ((float) ($zona['costo'] ?? 0) > 0) $c->items()->create(['product_id' => null, 'descripcion' => mb_substr('Envío · ' . $zona['nombre'], 0, 150), 'cantidad' => 1, 'precio_unit' => round((float) $zona['costo'], 2), 'alicuota_iva' => $ri ? 21 : 0, 'estado' => 'entregado', 'va_cocina' => false, 'ronda' => (int) ($c->items()->max('ronda') ?? 1)]);
        }
        $c->forceFill(['zona' => $zona['nombre'] ?? null, 'costo_envio' => round((float) ($zona['costo'] ?? 0), 2)])->save();
        $c->recalcular();
    }

    public function salio(Comanda $c, ?string $repartidor): void
    {
        abort_unless($c->tipo === 'delivery' && in_array($c->estado, ['abierta', 'cuenta'], true), 422, 'Sólo un delivery abierto puede salir.');
        $c->forceFill(['repartidor' => $repartidor ?: null, 'salio_en' => now()])->save();
        $c->items()->whereIn('estado', ['pedido', 'cocina', 'listo'])->update(['estado' => 'entregado']);
        if ($c->telefono) {
            $b = $c->business; $z = self::zona($b, $c->zona); $demora = (int) ($z['demora'] ?? 0);
            try { app(WhatsappPedidosService::class)->responder($b, $c->telefono, "¡Hola! Tu pedido {$c->numeroFormateado()} de {$b->name} ya salió" . ($repartidor ? " con {$repartidor}" : '') . ($demora ? ", llega en unos {$demora} minutos" : '') . ". ¡Buen provecho!"); } catch (\Throwable) {}
        }
        AuditLog::registrar('delivery_salio', $c, "Salió el delivery {$c->numeroFormateado()}" . ($repartidor ? " con {$repartidor}" : ''));
    }

    // Entregado: se cobra lo que falta con el medio que trajo el repartidor y la comanda se cierra.
    public function entregado(Comanda $c, string $medio, ComandaService $svc): array
    {
        abort_unless($c->tipo === 'delivery', 422, 'No es un delivery.');
        $c->forceFill(['entregado_en' => now(), 'salio_en' => $c->salio_en ?? now()])->save();
        $pend = $c->items()->where('estado', '!=', 'anulado')->get()->sum(fn($i) => ((float) $i->cantidad - (float) $i->pagado) * (float) $i->precio_unit);
        if ($pend > 0.005) return $svc->cobrar($c, ['medios' => [['medio' => $medio, 'monto' => round($pend, 2)]]], null);
        if (in_array($c->estado, ['abierta', 'cuenta'], true)) $c->update(['estado' => 'cerrada', 'cerrada_en' => now()]);
        return ['comanda' => $c->fresh(), 'cerrada' => true];
    }

    // El tablero del reparto: preparando → listos para salir → en camino → entregados hoy.
    public function tablero(int $locationId): array
    {
        $abiertas = Comanda::abiertas()->where('business_location_id', $locationId)->where('tipo', 'delivery')->with('items')->orderBy('abierta_en')->get();
        $hoy = Comanda::where('business_location_id', $locationId)->where('tipo', 'delivery')->where('estado', 'cerrada')->whereDate('cerrada_en', today())->with('items')->orderByDesc('cerrada_en')->get();
        $arr = function (Comanda $c) {
            $its = $c->items->where('estado', '!=', 'anulado')->filter(fn($i) => $i->product_id || ! str_starts_with($i->descripcion, 'Envío'));
            $listo = $its->isNotEmpty() && $its->every(fn($i) => in_array($i->estado, ['listo', 'entregado'], true));
            return ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'cliente' => $c->cliente, 'direccion' => $c->direccion, 'telefono' => $c->telefono, 'zona' => $c->zona, 'costo_envio' => (float) $c->costo_envio, 'total' => (float) $c->total, 'repartidor' => $c->repartidor,
                'minutos' => $c->abierta_en ? (int) $c->abierta_en->diffInMinutes(now()) : 0, 'salio' => $c->salio_en?->format('H:i'), 'en_camino_min' => $c->salio_en && ! $c->entregado_en ? (int) $c->salio_en->diffInMinutes(now()) : null, 'entregado' => $c->entregado_en?->format('H:i'),
                'items' => $its->map(fn($i) => ['descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'estado' => $i->estado])->values(), 'listo' => $listo, 'pagado' => $its->isNotEmpty() && $its->every(fn($i) => (float) $i->pagado >= (float) $i->cantidad - 0.0005)];
        };
        $enCamino = $abiertas->whereNotNull('salio_en')->map($arr)->values(); $sinSalir = $abiertas->whereNull('salio_en')->map($arr);
        $entregados = $hoy->map($arr)->values();
        $dem = $hoy->filter(fn($c) => $c->salio_en && $c->entregado_en)->map(fn($c) => $c->salio_en->diffInMinutes($c->entregado_en));
        return ['preparando' => $sinSalir->where('listo', false)->values(), 'listos' => $sinSalir->where('listo', true)->values(), 'en_camino' => $enCamino, 'entregados' => $entregados,
            'kpis' => ['en_curso' => $abiertas->count(), 'entregados_hoy' => $entregados->count(), 'envios_hoy' => round((float) $hoy->sum('costo_envio'), 2), 'vendido_hoy' => round((float) $hoy->sum('total'), 2), 'demora' => $dem->count() ? (int) round($dem->avg()) : null]];
    }
}
