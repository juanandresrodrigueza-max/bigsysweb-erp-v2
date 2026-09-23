<?php

namespace App\Services\Producto;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Centro de ayuda: artículos en docs/ayuda/*.md con encabezado (titulo, modulo, rutas, orden). Se buscan por texto y se
// eligen según la pantalla en la que está el usuario.
class AyudaService
{
    private function dir(): string { return base_path('docs/ayuda'); }

    public function articulos(): array
    {
        $ver = @filemtime($this->dir()) ?: 0;
        return Cache::remember("ayuda.articulos.{$ver}", 3600, function () {
            $out = [];
            foreach (glob($this->dir() . '/*.md') ?: [] as $f) {
                $raw = file_get_contents($f); $meta = [];
                if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
                    foreach (explode("\n", $m[1]) as $l) if (preg_match('/^(\w+):\s*(.*)$/', trim($l), $k)) $meta[$k[1]] = trim($k[2]);
                    $raw = substr($raw, strlen($m[0]));
                }
                $slug = basename($f, '.md');
                $out[$slug] = [
                    'slug' => $slug, 'titulo' => $meta['titulo'] ?? Str::headline($slug), 'modulo' => $meta['modulo'] ?? null,
                    'rutas' => array_values(array_filter(array_map('trim', explode(',', $meta['rutas'] ?? '')))), 'orden' => (int) ($meta['orden'] ?? 99),
                    'resumen' => $meta['resumen'] ?? Str::limit(trim(preg_replace('/[#*`\[\]_>-]+/', ' ', Str::before($raw, "\n\n"))), 140),
                    'texto' => $raw,
                ];
            }
            uasort($out, fn($a, $b) => [$a['orden'], $a['titulo']] <=> [$b['orden'], $b['titulo']]);
            return $out;
        });
    }

    public function lista(): array
    {
        return array_values(array_map(fn($a) => ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'modulo' => $a['modulo'], 'resumen' => $a['resumen']], $this->articulos()));
    }

    public function articulo(string $slug): ?array
    {
        $a = $this->articulos()[$slug] ?? null;
        if (! $a) return null;
        return ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'modulo' => $a['modulo'], 'resumen' => $a['resumen'], 'html' => Str::markdown($a['texto'], ['html_input' => 'strip', 'allow_unsafe_links' => false]), 'secciones' => $this->secciones($a['texto'])];
    }

    private function secciones(string $md): array
    {
        preg_match_all('/^##\s+(.+)$/m', $md, $m);
        return array_map(fn($t) => ['titulo' => $t, 'ancla' => Str::slug($t)], $m[1]);
    }

    // Búsqueda simple: puntúa título, resumen y cuerpo; devuelve hasta 8 con el fragmento donde aparece.
    public function buscar(string $q): array
    {
        $q = Str::lower(trim($q)); if (mb_strlen($q) < 2) return [];
        $palabras = array_filter(preg_split('/\s+/', $q));
        $res = [];
        foreach ($this->articulos() as $a) {
            $titulo = Str::lower($a['titulo']); $texto = Str::lower($a['texto']); $pts = 0;
            // Raíz de la palabra (anular → anul) para que "anular" encuentre "anula" y "anulo".
            foreach ($palabras as $p) { $raiz = mb_strlen($p) >= 5 ? mb_substr($p, 0, mb_strlen($p) - 2) : $p; if (str_contains($titulo, $raiz)) $pts += 10; $pts += min(5, substr_count($texto, $raiz)) + 2 * min(3, substr_count($texto, $p)); }
            if ($pts === 0) continue;
            $p0 = $palabras[array_key_first($palabras)]; $pos = mb_strpos($texto, $p0); if ($pos === false) $pos = mb_strpos($texto, mb_strlen($p0) >= 5 ? mb_substr($p0, 0, mb_strlen($p0) - 2) : $p0);
            $frag = $pos !== false ? trim(preg_replace('/[#*`\[\]_>]+/', '', mb_substr($a['texto'], max(0, $pos - 60), 180))) : $a['resumen'];
            $res[] = ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'modulo' => $a['modulo'], 'fragmento' => Str::limit($frag, 170), 'puntos' => $pts];
        }
        usort($res, fn($x, $y) => $y['puntos'] <=> $x['puntos']);
        return array_slice($res, 0, 8);
    }

    // Artículo que corresponde a la pantalla actual (la ruta más específica gana).
    public function paraRuta(string $path): ?array
    {
        $path = '/' . ltrim($path, '/'); $mejor = null; $largo = -1;
        foreach ($this->articulos() as $a) foreach ($a['rutas'] as $r) {
            $r = '/' . ltrim($r, '/');
            if (($path === $r || str_starts_with($path, rtrim($r, '/') . '/')) && strlen($r) > $largo) { $mejor = $a['slug']; $largo = strlen($r); }
        }
        return $mejor ? $this->articulo($mejor) : null;
    }

    // Pasos del tour guiado por pantalla: selector (data-tour) + texto.
    public static function tour(string $pantalla = 'general'): array
    {
        return match ($pantalla) {
            default => [
                ['sel' => '[data-tour="menu"]', 'titulo' => 'El menú', 'texto' => 'Todo el sistema está acá, agrupado por lo que hacés: vender, comprar, operar, finanzas. Lo que no está en tu plan no aparece.'],
                ['sel' => '[data-tour="sucursal"]', 'titulo' => 'Sucursal activa', 'texto' => 'Si tenés más de una sucursal, acá elegís en cuál estás trabajando. Cada venta, cobro y movimiento de stock queda en la sucursal activa.'],
                ['sel' => '[data-tour="buscar"]', 'titulo' => 'Buscar todo', 'texto' => 'Ctrl+K abre el buscador: clientes, artículos, comprobantes por número, pantallas y acciones rápidas. Es la forma más rápida de moverse.'],
                ['sel' => '[data-tour="alertas"]', 'titulo' => 'Alertas', 'texto' => 'Stock bajo, facturas vencidas, cheques por vencer, pedidos nuevos. Lo importante te busca a vos.'],
                ['sel' => '[data-tour="ayuda"]', 'titulo' => 'Ayuda', 'texto' => 'Desde cualquier pantalla, el signo de pregunta abre la guía de esa pantalla. Y si no alcanza, Soporte está a un clic.'],
                ['sel' => '[data-tour="asistente"]', 'titulo' => 'Asistente', 'texto' => 'Preguntale en criollo: "¿cuánto me debe López?", "cobrale 5000 a Pérez en efectivo". Consulta y también hace cosas, siempre con tu confirmación.'],
            ],
        };
    }
}
