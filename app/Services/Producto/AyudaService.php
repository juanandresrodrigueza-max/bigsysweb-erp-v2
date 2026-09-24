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
        // La versión del caché sigue a la última modificación de cualquier artículo (editar un archivo alcanza para refrescar).
        $ver = max(array_map('filemtime', glob($this->dir() . '/*.md') ?: []) ?: [0]);
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
        $html = Str::markdown($a['texto'], ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        // Cada título de sección lleva su ancla, para llegar directo desde la búsqueda.
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/s', fn($m) => '<h2 id="' . Str::slug(html_entity_decode(strip_tags($m[1]))) . '">' . $m[1] . '</h2>', $html);
        return ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'modulo' => $a['modulo'], 'resumen' => $a['resumen'], 'html' => $html, 'secciones' => array_map(fn($x) => ['titulo' => $x['titulo'], 'ancla' => $x['ancla']], $this->secciones($a['texto']))];
    }

    // Parte el markdown en secciones (## título + cuerpo). La introducción, si la hay, es la sección sin título.
    public function secciones(string $md): array
    {
        $partes = preg_split('/^##\s+(.+)$/m', $md, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = [];
        if (trim($partes[0] ?? '') !== '') $out[] = ['titulo' => 'Introducción', 'ancla' => '', 'texto' => trim($partes[0])];
        for ($i = 1; $i < count($partes); $i += 2) $out[] = ['titulo' => trim($partes[$i]), 'ancla' => Str::slug($partes[$i]), 'texto' => trim($partes[$i + 1] ?? '')];
        return $out;
    }

    // Índice completo: artículos con sus secciones (para navegar por sección).
    public function indice(): array
    {
        return array_values(array_map(fn($a) => ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'modulo' => $a['modulo'], 'secciones' => array_values(array_filter(array_map(fn($x) => ['titulo' => $x['titulo'], 'ancla' => $x['ancla']], $this->secciones($a['texto'])), fn($x) => $x['ancla'] !== ''))], $this->articulos()));
    }

    private static function norm(string $t): string { return Str::lower(Str::ascii($t)); }
    private static function contar(string $texto, string $raiz): int { return preg_match_all('/(?<![a-z0-9])' . preg_quote($raiz, '/') . '/', $texto); }
    private static function raiz(string $p): string { return mb_strlen($p) >= 5 ? mb_substr($p, 0, mb_strlen($p) - 2) : $p; }
    private const VACIAS = ['como', 'para', 'que', 'una', 'uno', 'los', 'las', 'del', 'con', 'por', 'sin', 'mi', 'el', 'la', 'de', 'en', 'un', 'se', 'al', 'lo', 'es', 'hago', 'puedo', 'donde', 'cuando'];

    // Búsqueda por sección: puntúa título del artículo, título de la sección y cuerpo, sin acentos y por raíz de palabra
    // ("anular" encuentra "anula" y "anulo"). Devuelve hasta 10 secciones con el fragmento donde aparece.
    public function buscar(string $q, int $max = 10): array
    {
        $qn = self::norm(trim($q)); if (mb_strlen($qn) < 2) return [];
        $palabras = array_values(array_filter(preg_split('/[^a-z0-9]+/', $qn), fn($p) => mb_strlen($p) >= 2 && ! in_array($p, self::VACIAS, true)));
        if (! $palabras) $palabras = array_values(array_filter(preg_split('/[^a-z0-9]+/', $qn)));
        $res = [];
        foreach ($this->articulos() as $a) {
            $tituloA = self::norm($a['titulo']);
            foreach ($this->secciones($a['texto']) as $sec) {
                $tituloS = self::norm($sec['titulo']); $texto = self::norm($sec['texto']); $pts = 0; $hits = 0;
                foreach ($palabras as $p) {
                    // La raíz cuenta solo al comienzo de una palabra: "anul" no tiene que encontrar "manual".
                    $r = self::raiz($p); $n = self::contar($texto, $r);
                    if (self::contar($tituloA, $r)) $pts += 4;
                    if (self::contar($tituloS, $r)) $pts += 20; // el título de la sección pesa más que las menciones en el cuerpo
                    if ($n) { $hits++; $pts += min(6, $n) + 2 * min(3, self::contar($texto, $p)); }
                }
                if ($pts === 0) continue;
                if (count($palabras) > 1 && $hits === count($palabras)) $pts += 8; // todas las palabras en la misma sección
                if (str_contains($texto, $qn)) $pts += 12; // la frase entera
                $p0 = $palabras[0]; $pos = mb_strpos($texto, $p0); if ($pos === false) $pos = mb_strpos($texto, self::raiz($p0));
                $frag = $pos !== false ? trim(preg_replace('/[#*`\[\]_>]+/', '', mb_substr($sec['texto'], max(0, $pos - 60), 190))) : Str::limit(preg_replace('/[#*`\[\]_>]+/', '', $sec['texto']), 190);
                $res[] = ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'modulo' => $a['modulo'], 'seccion' => $sec['titulo'], 'ancla' => $sec['ancla'], 'url' => '/ayuda/' . $a['slug'] . ($sec['ancla'] ? '#' . $sec['ancla'] : ''), 'fragmento' => Str::limit($frag, 180), 'puntos' => $pts];
            }
        }
        usort($res, fn($x, $y) => $y['puntos'] <=> $x['puntos']);
        return array_slice($res, 0, $max);
    }

    // Pregunta en lenguaje natural: con clave de IA responde usando las secciones que más coinciden como contexto y cita las fuentes.
    public function preguntar(string $q): array
    {
        $fuentes = $this->buscar($q, 6);
        $key = config('services.anthropic.api_key');
        if (! $key || ! $fuentes) return ['respuesta' => null, 'fuentes' => $fuentes, 'ia' => (bool) $key];
        $contexto = collect($fuentes)->map(function ($f) { $sec = collect($this->secciones($this->articulos()[$f['slug']]['texto']))->firstWhere('ancla', $f['ancla']); return "### {$f['titulo']} › {$f['seccion']}\n" . ($sec['texto'] ?? ''); })->implode("\n\n");
        try {
            $r = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(25)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 500,
                'system' => 'Sos la ayuda de BigSysWeb, un ERP para PyMEs argentinas. Respondé la pregunta del usuario SOLO con la información de las guías que te paso, en español rioplatense, corto y con pasos concretos (dónde hacer clic). Si las guías no lo cubren, decilo y sugerí escribir a soporte. No inventes pantallas ni botones.',
                'messages' => [['role' => 'user', 'content' => "Guías:\n\n{$contexto}\n\nPregunta: {$q}"]]]);
            $texto = $r->successful() ? trim((string) ($r->json('content.0.text') ?? '')) : null;
        } catch (\Throwable $e) { $texto = null; }
        return ['respuesta' => $texto ?: null, 'fuentes' => $fuentes, 'ia' => true];
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
