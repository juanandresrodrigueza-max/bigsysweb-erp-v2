<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Services\Envios\EnvioService;
use App\Services\Ventas\LinkPagoService;
use Illuminate\Http\Request;

// Página pública de un comprobante (sin login): ver, descargar PDF, aprobar presupuesto, pagar.
class PublicoController extends Controller
{
    private function buscar(string $token): Comprobante
    {
        return Comprobante::withoutGlobalScopes()->where('public_token', $token)->where('estado', '!=', 'borrador')->with(['items', 'contact', 'business'])->firstOrFail();
    }

    public function ver(string $token, Request $request)
    {
        $c = $this->buscar($token);
        return view('publico.comprobante', ['c' => $c, 'b' => $c->business, 'pago' => $request->query('pago')]);
    }

    public function pdf(string $token, EnvioService $envios)
    {
        $c = $this->buscar($token);
        [$bin, $nombre] = $envios->pdf($c);
        return response($bin, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $nombre . '"']);
    }

    // El cliente aprueba o rechaza un presupuesto desde el link.
    public function responder(string $token, Request $request)
    {
        $c = $this->buscar($token);
        abort_if($c->tipo !== 'PRE', 404);
        $d = $request->validate(['respuesta' => 'required|in:aprobar,rechazar', 'comentario' => 'nullable|string|max:500']);
        $ok = $d['respuesta'] === 'aprobar';
        $c->forceFill(['aprobado_en' => $ok ? now() : null, 'rechazado_en' => $ok ? null : now(), 'respuesta_cliente' => $d['comentario'] ?? null])->save();
        Alerta::emitir(['business_id' => $c->business_id, 'business_location_id' => $c->business_location_id, 'modulo' => 'comprobantes', 'tipo' => 'presupuesto_respondido', 'modelo' => 'Comprobante', 'modelo_id' => $c->id, 'severidad' => $ok ? 'info' : 'aviso', 'titulo' => ($ok ? 'Presupuesto aprobado: ' : 'Presupuesto rechazado: ') . $c->contact?->name, 'detalle' => "{$c->numeroFormateado()} por $ " . number_format((float) $c->total, 2, ',', '.') . ($d['comentario'] ?? null ? " · \"{$d['comentario']}\"" : '') . ($ok ? '. Podés facturarlo o hacer el remito.' : ''), 'url' => "/comprobantes/{$c->id}"]);
        Alerta::withoutGlobalScopes()->where('modelo', 'Comprobante')->where('modelo_id', $c->id)->where('tipo', 'presupuesto_sin_respuesta')->update(['resuelta_en' => now()]);
        AuditLog::registrar('editar', $c, ($ok ? 'Cliente aprobó ' : 'Cliente rechazó ') . "presupuesto {$c->numeroFormateado()} desde el link");
        return redirect('/p/' . $token)->with('respondido', true);
    }

    // Simulación de pago cuando la empresa no tiene MercadoPago: acredita el saldo como cobro online.
    public function pagarSimulado(string $token, LinkPagoService $links)
    {
        $c = $this->buscar($token);
        abort_if($c->link_pago_id !== 'simulado', 404);
        $links->acreditar($c, (float) $c->saldo, 'SIMULADO-' . strtoupper(substr($token, 0, 6)));
        return redirect('/p/' . $token . '?pago=ok');
    }
}
