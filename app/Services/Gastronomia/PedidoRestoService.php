<?php

namespace App\Services\Gastronomia;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Models\Product;

// Fase 53.2 · Big Restó: comandas que llegan de palabra (el mozo le dicta a Bigcito) o por WhatsApp (el cliente escribe como habla).
// Convierte "dos bifes jugosos con papas y una coca" en una comanda con las opciones de cada plato.
class PedidoRestoService
{
    // ¿Los pedidos por WhatsApp de esta empresa van a Big Restó (comanda) en vez de a Pedidos web?
    public static function activo(Business $b): bool
    {
        return in_array('gastronomia', $b->verticalesPermitidos(), true) && (bool) ($b->gastronomia_config['whatsapp_a_resto'] ?? true);
    }

    public static function norm(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9 ]+/', ' ', strtr(mb_strtolower((string) $s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'])));
    }

    // Opciones dichas en lenguaje natural ("jugoso", "con papas", "sin cebolla") → las de la carta del plato. Devuelve [elegidas por grupo, lo que no matcheó].
    public static function opciones(Product $p, array $textos): array
    {
        $out = []; $sobra = [];
        $palabras = fn($s) => array_values(array_filter(explode(' ', preg_replace('/^(con|sin|mas|\+)\s+/u', '', $s)), fn($w) => mb_strlen($w) > 2));
        foreach ($textos as $t) {
            $nt = self::norm($t); if ($nt === '') continue; $hit = false; $tw = $palabras($nt);
            foreach ($p->modificadores ?? [] as $g) foreach ($g['opciones'] ?? [] as $o) {
                $no = self::norm($o['nombre']); $ow = explode(' ', $no);
                // Coincide si una contiene a la otra, o si todas las palabras dichas ("papas") están en la opción ("papas fritas").
                $porPalabras = $tw && collect($tw)->every(fn($w) => collect($ow)->contains(fn($x) => str_starts_with($x, $w) || str_starts_with($w, $x)));
                if ($no !== '' && (str_contains($nt, $no) || str_contains($no, $nt) || $porPalabras)) {
                    if (empty($g['multiple'])) $out[$g['nombre']] = [$o['nombre']]; else $out[$g['nombre']] = array_values(array_unique(array_merge($out[$g['nombre']] ?? [], [$o['nombre']])));
                    $hit = true; break 2;
                }
            }
            if (! $hit) $sobra[] = $t;
        }
        return [$out, $sobra];
    }

    // Arma la comanda. $d: tipo (mesa|mostrador|delivery), mesa_id, cliente, telefono, direccion, cubiertos, user_id, enviar (bool),
    // items: [{product_id, cantidad, opciones: [texto...], nota}]. Las opciones obligatorias que falten se piden en 'faltan'.
    public function crear(Business $b, array $d): array
    {
        $mesa = ! empty($d['mesa_id']) ? Mesa::withoutGlobalScopes()->where('business_id', $b->id)->find($d['mesa_id']) : null;
        $c = $mesa?->comandaAbierta ?? Comanda::create([
            'business_id' => $b->id, 'business_location_id' => $mesa?->business_location_id ?? $b->locations()->where('is_default', true)->value('id'), 'mesa_id' => $mesa?->id, 'user_id' => $d['user_id'] ?? $b->owner_id,
            'numero' => (Comanda::withoutGlobalScopes()->where('business_id', $b->id)->max('numero') ?? 0) + 1, 'tipo' => $mesa ? 'mesa' : ($d['tipo'] ?? 'mostrador'), 'estado' => 'abierta', 'total' => 0, 'propina' => 0, 'descuento' => 0,
            'cubiertos' => $d['cubiertos'] ?? ($mesa ? 2 : 0), 'cliente' => $d['cliente'] ?? null, 'direccion' => $d['direccion'] ?? null, 'telefono' => $d['telefono'] ?? null, 'abierta_en' => now(),
        ]);
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $ronda = (int) ($c->items()->max('ronda') ?? 0) + 1; $primera = $b->estacionesCocina()[0]['nombre'] ?? 'Cocina';
        $confirma = ! empty($b->gastronomia_config['qr_confirma']) && ! ($d['enviar'] ?? false);
        $faltan = []; $cargados = [];
        foreach ($d['items'] as $it) {
            $p = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->find($it['product_id'] ?? 0); if (! $p) continue;
            [$elegidas, $sobra] = self::opciones($p, (array) ($it['opciones'] ?? []));
            foreach ($p->modificadores ?? [] as $g) if (! empty($g['obligatorio']) && empty($elegidas[$g['nombre']])) { $faltan[] = ['plato' => $p->name, 'grupo' => $g['nombre'], 'opciones' => array_column($g['opciones'] ?? [], 'nombre')]; continue 2; }
            [$mods, $extra] = ComandaService::modificadores($p, $elegidas);
            $al = $ri ? (float) $p->iva : 0; $hh = HorariosService::happyHour($b, $p);
            $nota = trim(implode(', ', array_filter(array_merge($sobra, [$it['nota'] ?? null])))) ?: null;
            $c->items()->create(['product_id' => $p->id, 'descripcion' => mb_substr($p->name . ($mods ? ' (' . implode(', ', array_column($mods, 'nombre')) . ')' : '') . ($hh ? " [{$hh['nombre']}]" : ''), 0, 250), 'modificadores' => $mods ?: null, 'estacion' => $p->estacion ?: $primera,
                'cantidad' => max(0.001, (float) ($it['cantidad'] ?? 1)), 'precio_unit' => HorariosService::aplicar(round((float) $p->price * (1 + $al / 100), 2), $hh) + $extra, 'alicuota_iva' => $al, 'notas' => $nota, 'va_cocina' => $p->va_cocina, 'ronda' => $ronda,
                'estado' => ($d['enviar'] ?? false) || ! $confirma ? ($p->va_cocina ? 'cocina' : 'listo') : 'pedido', 'enviado_en' => ($d['enviar'] ?? false) || ! $confirma ? now() : null]);
            $cargados[] = ['plato' => $p->name, 'cantidad' => (float) ($it['cantidad'] ?? 1), 'opciones' => array_column($mods ?? [], 'nombre'), 'nota' => $nota];
        }
        $c->recalcular();
        return ['comanda' => $c->fresh(), 'cargados' => $cargados, 'faltan' => $faltan];
    }

    // Pedido por WhatsApp: la interpretación de WhatsappPedidosService (con opciones) → comanda de delivery o mostrador. Avisa en el salón.
    public function desdeWhatsapp(Business $b, array $i, ?string $telefono): array
    {
        $r = $this->crear($b, ['tipo' => ($i['entrega'] ?? 'retiro') === 'envio' ? 'delivery' : 'mostrador', 'cliente' => $i['nombre'] ?? ('WhatsApp ' . ($telefono ?: '')), 'telefono' => $telefono, 'direccion' => $i['direccion'] ?? null,
            'items' => array_map(fn($x) => ['product_id' => $x['product_id'], 'cantidad' => $x['cantidad'], 'opciones' => $x['opciones'] ?? [], 'nota' => $x['nota'] ?? null], $i['items'])]);
        $c = $r['comanda'];
        // Si no se pudo cargar nada (faltó una opción obligatoria), no queda una comanda vacía dando vueltas.
        if (! $c->items()->count()) { $c->delete(); $r['comanda'] = null; return $r; }
        if ($c->items()->count()) Alerta::emitir(['business_id' => $b->id, 'business_location_id' => $c->business_location_id, 'modulo' => 'gastronomia', 'tipo' => 'pedido_whatsapp', 'modelo' => 'Comanda', 'modelo_id' => $c->id, 'severidad' => 'info', 'titulo' => "Pedido por WhatsApp {$c->numeroFormateado()}" . ($c->tipo === 'delivery' ? ' · delivery' : ' · para retirar'), 'detalle' => collect($r['cargados'])->map(fn($x) => rtrim(rtrim(number_format($x['cantidad'], 2, ',', '.'), '0'), ',') . ' ' . $x['plato'])->implode(', '), 'url' => "/gastronomia/comandas/{$c->id}"]);
        return $r;
    }
}
