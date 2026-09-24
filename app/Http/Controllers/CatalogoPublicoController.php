<?php

namespace App\Http\Controllers;

use App\Models\Catalogo;
use App\Models\Contact;
use App\Services\Ventas\CatalogoService;
use Illuminate\Http\Request;

// Catálogo público por link (sin login). Con ?c=<token del portal del cliente> muestra los precios de ese cliente.
class CatalogoPublicoController extends Controller
{
    public function __construct(private CatalogoService $svc) {}

    private function resolver(string $token, Request $request): array
    {
        $cat = Catalogo::withoutGlobalScopes()->where('token', $token)->where('activo', true)->firstOrFail();
        $cli = $request->filled('c') ? Contact::withoutGlobalScopes()->where('business_id', $cat->business_id)->where('portal_token', (string) $request->query('c'))->first() : null;
        return [$cat, $cli];
    }

    public function ver(string $token, Request $request)
    {
        [$cat, $cli] = $this->resolver($token, $request);
        $cat->forceFill(['vistas' => $cat->vistas + 1, 'visto_en' => now()])->saveQuietly();
        return response()->view('catalogo.ver', $this->svc->datos($cat, $cli))->header('X-Robots-Tag', 'noindex');
    }

    public function pdf(string $token, Request $request)
    {
        [$cat, $cli] = $this->resolver($token, $request);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('catalogo.pdf', $this->svc->datos($cat, $cli))->setPaper('a4');
        return $pdf->download(\Illuminate\Support\Str::slug($cat->nombre . ($cli ? ' ' . $cli->name : '')) . '.pdf');
    }
}
