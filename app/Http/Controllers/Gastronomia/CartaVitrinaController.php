<?php

namespace App\Http\Controllers\Gastronomia;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Product;
use App\Services\Gastronomia\CartaVitrinaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Fase 127: la carta vitrina (Big Restó → Carta) y las fotos públicas de los platos y de la portada.
class CartaVitrinaController extends Controller
{
    public function __construct(private CartaVitrinaService $svc) {}

    public function guardar(Request $request)
    {
        $d = $request->validate(['menu_estilo' => 'required|in:lista,vitrina', 'menu_historia_titulo' => 'nullable|string|max:80', 'menu_historia' => 'nullable|string|max:1200', 'menu_horario' => 'nullable|string|max:160']);
        $b = $request->user()->business;
        $this->svc->guardarConfig($b, $d);
        AuditLog::registrar('editar', $b, 'Carta vitrina: ' . ($d['menu_estilo'] === 'vitrina' ? 'activada' : 'carta en lista'));
        return back()->with('success', $d['menu_estilo'] === 'vitrina' ? 'Listo: la carta del QR se ve como vitrina.' : 'Listo: la carta del QR se ve como lista.');
    }

    public function foto(Request $request, int $id)
    {
        $request->validate(['foto' => 'required|file|max:' . (CartaVitrinaService::MB * 1024)], ['foto.max' => 'La foto puede pesar hasta ' . CartaVitrinaService::MB . ' MB.']);
        $p = Product::findOrFail($id);
        $this->svc->subirFoto($p, $request->file('foto'));
        AuditLog::registrar('editar', $p, "Subió la foto de {$p->name} para la carta");
        return back()->with('success', "{$p->name}: foto cargada.");
    }

    public function quitarFoto(int $id)
    {
        $p = Product::findOrFail($id);
        $this->svc->quitarFoto($p);
        return back()->with('success', "{$p->name}: foto quitada.");
    }

    public function portada(Request $request)
    {
        $request->validate(['foto' => 'required|file|max:' . (CartaVitrinaService::MB * 1024)]);
        $this->svc->subirPortada($request->user()->business, $request->file('foto'));
        return back()->with('success', 'Foto del local cargada.');
    }

    public function quitarPortada(Request $request)
    {
        $this->svc->quitarPortada($request->user()->business);
        return back()->with('success', 'Foto del local quitada.');
    }

    // ─── Públicas: las ve cualquiera que abra la carta ───
    public function verPlato(int $id)
    {
        $p = Product::withoutGlobalScopes()->whereKey($id)->whereNotNull('foto_carta')->firstOrFail();
        return $this->servir($p->foto_carta);
    }

    public function verPortada(int $id)
    {
        $b = Business::findOrFail($id);
        abort_if(empty($b->tienda['menu_portada']), 404);
        return $this->servir($b->tienda['menu_portada']);
    }

    private function servir(string $ruta)
    {
        abort_unless(Storage::disk('local')->exists($ruta), 404);
        return Storage::disk('local')->response($ruta, null, ['Cache-Control' => 'public, max-age=604800']);
    }
}
