<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\PedidoWeb;
use App\Services\Canales\TiendaService;
use App\Services\Ventas\FidelizacionService;
use App\Services\Ventas\LinkPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Portal del cliente: con su link (token) ve su cuenta, sus comprobantes, paga online, repite pedidos y ve sus puntos.
class PortalController extends Controller
{
    public static function token(Contact $c): string
    {
        if (! $c->portal_token) $c->forceFill(['portal_token' => Str::random(48)])->save();
        return $c->portal_token;
    }

    public static function url(Contact $c): string { return url('/portal/' . self::token($c)); }

    public function ver(string $token, Request $request, TiendaService $tienda, FidelizacionService $fid)
    {
        $c = Contact::withoutGlobalScopes()->where('portal_token', $token)->firstOrFail();
        $b = $c->business;
        $request->session()->put('portal_contact_id', $c->id);
        $comps = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $c->id)->where('direccion', 'venta')->where('estado', '!=', 'borrador')->orderByDesc('fecha')->orderByDesc('id')->limit(40)->get();
        $cc = CuentaCorriente::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $c->id)->orderByDesc('fecha')->orderByDesc('id')->limit(30)->get();
        $pedidos = PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $c->id)->latest()->limit(20)->get();
        $cfg = $tienda->config($b);
        $fcfg = $fid->config($b);
        return view('portal.index', ['b' => $b, 'c' => $c, 'comps' => $comps, 'cc' => $cc, 'pedidos' => $pedidos, 'cfg' => $cfg, 'fcfg' => $fcfg, 'puntosPesos' => $fid->valorEnPesos($b, (float) $c->puntos), 'pendientes' => $comps->filter(fn($x) => $x->esFactura() && (float) $x->saldo > 0.005)]);
    }

    public function pagar(string $token, int $id, LinkPagoService $links)
    {
        $c = Contact::withoutGlobalScopes()->where('portal_token', $token)->firstOrFail();
        $comp = Comprobante::withoutGlobalScopes()->where('business_id', $c->business_id)->where('contact_id', $c->id)->findOrFail($id);
        return redirect($links->crear($comp));
    }
}
