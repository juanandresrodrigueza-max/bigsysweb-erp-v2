<?php

namespace App\Http\Controllers;

use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\OrdenCompra;
use App\Models\Pago;
use App\Services\Envios\EnvioService;
use Illuminate\Http\Request;

// Envío de documentos por mail o WhatsApp desde cualquier pantalla.
class EnviosController extends Controller
{
    public function __construct(private EnvioService $service) {}

    public function enviar(Request $request)
    {
        $d = $request->validate(['modelo' => 'required|in:Comprobante,Cobro,Pago,OrdenCompra,Contact', 'id' => 'required|integer', 'canal' => 'required|in:mail,whatsapp', 'destino' => 'nullable|string|max:150', 'mensaje' => 'nullable|string|max:2000', 'adjuntar' => 'boolean']);
        $m = match ($d['modelo']) { 'Comprobante' => Comprobante::findOrFail($d['id']), 'Cobro' => Cobro::findOrFail($d['id']), 'Pago' => Pago::findOrFail($d['id']), 'OrdenCompra' => OrdenCompra::findOrFail($d['id']), 'Contact' => Contact::findOrFail($d['id']) };
        $tipo = match ($d['modelo']) { 'Comprobante' => 'comprobante', 'Cobro' => 'recibo', 'Pago' => 'pago', 'OrdenCompra' => 'oc', 'Contact' => 'ficha' };
        $e = $this->service->enviar($m, $d['canal'], $d['destino'] ?? null, $tipo, $d['mensaje'] ?? null, $d['adjuntar'] ?? true);
        if ($e->estado === 'error') return back()->with('error', 'No se pudo enviar: ' . $e->error);
        if ($e->canal === 'whatsapp' && $e->estado === 'pendiente') return back()->with('success', 'Mensaje listo: se abre WhatsApp con el texto cargado.')->with('abrir', $e->link)->with('envio_id', $e->id);
        return back()->with('success', $e->canal === 'mail' ? "Enviado por mail a {$e->destino}." : 'Enviado por WhatsApp.');
    }

    public function marcar(int $id)
    {
        $this->service->marcarEnviado(Envio::findOrFail($id));
        return response()->json(['ok' => true]);
    }

    // Texto y destinos sugeridos para el modal de envío.
    public function borrador(Request $request)
    {
        $d = $request->validate(['modelo' => 'required|in:Comprobante,Cobro,Pago,OrdenCompra,Contact', 'id' => 'required|integer']);
        $m = match ($d['modelo']) { 'Comprobante' => Comprobante::findOrFail($d['id']), 'Cobro' => Cobro::findOrFail($d['id']), 'Pago' => Pago::findOrFail($d['id']), 'OrdenCompra' => OrdenCompra::findOrFail($d['id']), 'Contact' => Contact::findOrFail($d['id']) };
        $contact = $m instanceof Contact ? $m : $m->contact;
        $t = $this->service->texto($m, $request->user()->business);
        $b = $request->user()->business;
        // Personas de contacto: primero las que reciben este tipo de documento.
        $campo = match (true) { $m instanceof Cobro || $m instanceof Contact => 'recibe_cobranzas', $m instanceof Pago || $m instanceof OrdenCompra => 'recibe_pagos', default => 'recibe_comprobantes' };
        $personas = $contact ? $contact->personas->sortByDesc($campo)->values()->map(fn($p) => ['nombre' => $p->nombre, 'cargo' => $p->cargo, 'email' => $p->email, 'telefono' => $p->telefono, 'sugerido' => (bool) $p->$campo]) : collect();
        $sug = $personas->firstWhere('sugerido', true);
        return response()->json(['asunto' => $t['asunto'], 'cuerpo' => $t['cuerpo'], 'email' => ($sug['email'] ?? null) ?: $contact?->email, 'telefono' => ($sug['telefono'] ?? null) ?: ($contact?->mobile ?: $contact?->phone), 'personas' => $personas, 'whatsapp_api' => ! empty($b->whatsapp_settings['token']), 'mail_configurado' => config('mail.default') !== 'log',
            'historial' => Envio::where('modelo', $d['modelo'])->where('modelo_id', $d['id'])->latest()->limit(5)->get()->map(fn($e) => ['canal' => $e->canal, 'destino' => $e->destino, 'estado' => $e->estado, 'fecha' => $e->created_at->format('d/m H:i'), 'link' => $e->link, 'id' => $e->id])]);
    }
}
