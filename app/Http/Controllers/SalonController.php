<?php

namespace App\Http\Controllers;

use App\Models\CotizacionConsultor;
use App\Services\Stock\ConsultorService;
use Illuminate\Http\Request;

// Páginas públicas del Consultor: la pantalla del salón (llave de sólo lectura, sin login) y la cotización mandada por WhatsApp.
class SalonController extends Controller
{
    public function __construct(private ConsultorService $svc) {}

    public function ver(string $token)
    {
        $r = ConsultorService::pantallaPorToken($token); abort_unless($r, 404);
        $b = $r['business']; $p = $r['pantalla'];
        $cart = collect($b->pos['cartelera'] ?? [])->map(fn($c) => ['tipo' => $c['tipo'], 'url' => url("/salon/{$token}/cartelera/{$c['id']}")])->values()->all();
        return view('stock.salon', ['b' => $b, 'pantalla' => $p, 'token' => $token, 'cartelera' => $cart, 'segundos' => (int) ($b->pos['cartelera_segundos'] ?? 8), 'balanza' => \App\Support\Balanza::config($b)]);
    }

    public function catalogo(string $token)
    {
        $r = ConsultorService::pantallaPorToken($token); abort_unless($r, 404);
        return response()->json(['pantalla' => ['nombre' => $r['pantalla']['nombre'], 'lista' => $r['pantalla']['lista']], 'generado' => now()->toIso8601String(), 'items' => $this->svc->catalogoSalon($r['business'], (int) $r['pantalla']['lista'])]);
    }

    public function cartelera(string $token, string $id)
    {
        $r = ConsultorService::pantallaPorToken($token); abort_unless($r, 404);
        $item = collect($r['business']->pos['cartelera'] ?? [])->firstWhere('id', $id);
        abort_unless($item && \Illuminate\Support\Facades\Storage::disk('local')->exists($item['archivo']), 404);
        return \Illuminate\Support\Facades\Storage::disk('local')->response($item['archivo'], null, ['Content-Type' => $item['mime'] ?? 'application/octet-stream', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function cotizacion(string $token): CotizacionConsultor
    {
        $c = CotizacionConsultor::withoutGlobalScopes()->where('token', $token)->with('business')->firstOrFail();
        abort_unless($c->vigente(), 410, 'Esta cotización venció.');
        return $c;
    }

    public function cotizacionVer(string $token) { $c = $this->cotizacion($token); return view('stock.cotizacion', ['c' => $c, 'b' => $c->business, 'pdf' => false]); }

    public function cotizacionPdf(string $token)
    {
        $c = $this->cotizacion($token);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('stock.cotizacion', ['c' => $c, 'b' => $c->business, 'pdf' => true])->setPaper('a4');
        return $pdf->stream('cotizacion-' . $c->id . '.pdf');
    }
}
