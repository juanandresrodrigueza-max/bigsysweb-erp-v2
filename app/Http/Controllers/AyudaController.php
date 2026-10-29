<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Producto\AyudaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Centro de ayuda: guías por pantalla, búsqueda, ayuda contextual (el "?" del encabezado) y tour guiado.
class AyudaController extends Controller
{
    public function __construct(private AyudaService $ayuda) {}

    public function index(Request $request, ?string $slug = null)
    {
        $q = trim((string) $request->q);
        $articulo = $slug ? $this->ayuda->articulo($slug) : null;
        abort_if($slug && ! $articulo, 404);
        return Inertia::render('Ayuda', [
            'articulos' => $this->ayuda->lista(), 'articulo' => $articulo, 'q' => $q,
            'resultados' => $q !== '' ? $this->ayuda->buscar($q) : [], 'indice' => $this->ayuda->indice(), 'ia' => (bool) config('services.anthropic.api_key'),
            'soporte' => ['whatsapp' => \App\Models\SistemaConfig::get('soporte_whatsapp'), 'email' => \App\Models\SistemaConfig::get('soporte_email')],
            // Fase 40.4: lo que preguntaron y el manual no respondió (o no sirvió), para el dueño.
            'sin_respuesta' => $request->user()->esDueno() || $request->user()->is_superadmin ? \App\Models\AyudaPregunta::where('business_id', $request->user()->business_id)->where(fn($q) => $q->where('sin_respuesta', true)->orWhere('util', false))->latest('id')->limit(40)->get()->map(fn($p) => ['pregunta' => $p->pregunta, 'cuando' => $p->created_at->format('d/m'), 'no_sirvio' => $p->util === false])->values() : [],
            'guias' => collect(['arca', 'asistente', 'atajos', 'mercado-argentino', 'seguridad', 'api'])->filter(fn($g) => file_exists(base_path("docs/{$g}.md")))->values(),
        ]);
    }

    // Manual completo en una página imprimible: todos los artículos, en orden, con índice.
    public function manual(Request $request)
    {
        $arts = array_map(fn($a) => $this->ayuda->articulo($a['slug']), $this->ayuda->lista());
        $html = view('ayuda.manual', ['articulos' => $arts, 'indice' => $this->ayuda->indice()])->render();
        // Fase 40.4: el manual entero en un archivo, para leerlo sin internet.
        if ($request->boolean('descargar')) return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=manual-bigsysweb.html']);
        return response($html);
    }

    // Ayuda de la pantalla actual, para el panel lateral.
    public function contexto(Request $request)
    {
        $ruta = (string) $request->query('ruta', '/');
        $a = $this->ayuda->paraRuta(parse_url($ruta, PHP_URL_PATH) ?: '/');
        return response()->json(['articulo' => $a, 'sugeridos' => array_slice($this->ayuda->lista(), 0, 6), 'tour' => AyudaService::tour()]);
    }

    public function buscar(Request $request)
    {
        return response()->json($this->ayuda->buscar((string) $request->query('q', '')));
    }

    public function preguntar(Request $request)
    {
        $d = $request->validate(['q' => 'required|string|min:2|max:300', 'historial' => 'nullable|array|max:10', 'historial.*.rol' => 'required_with:historial|in:user,assistant', 'historial.*.texto' => 'required_with:historial|string|max:2000']);
        return response()->json($this->ayuda->preguntar($d['q'], $d['historial'] ?? []));
    }

    // ¿Te sirvió? 👍 / 👎
    public function voto(Request $request, int $id)
    {
        $d = $request->validate(['util' => 'required|boolean']);
        \App\Models\AyudaPregunta::where('user_id', $request->user()->id)->findOrFail($id)->update(['util' => $d['util']]);
        return response()->json(['ok' => true]);
    }

    // Guías técnicas de docs/ (ARCA, asistente, atajos…) renderizadas.
    public function guia(string $nombre)
    {
        abort_unless(preg_match('/^[a-z0-9-]+$/', $nombre) && file_exists($f = base_path("docs/{$nombre}.md")), 404);
        $md = file_get_contents($f);
        return Inertia::render('Ayuda', ['articulos' => $this->ayuda->lista(), 'q' => '', 'resultados' => [], 'guias' => [], 'soporte' => [],
            'articulo' => ['slug' => "guia-{$nombre}", 'titulo' => trim(ltrim(strtok($md, "\n"), '# ')), 'modulo' => null, 'resumen' => '', 'html' => \Illuminate\Support\Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]), 'secciones' => []]]);
    }

    public function tourVisto(Request $request)
    {
        $request->user()->forceFill(['tour_visto_en' => now()])->save();
        return response()->json(['ok' => true]);
    }

    public function tourReiniciar(Request $request)
    {
        $request->user()->forceFill(['tour_visto_en' => null])->save();
        return back()->with('success', 'El tour se muestra la próxima vez que entres a Inicio.');
    }
}
