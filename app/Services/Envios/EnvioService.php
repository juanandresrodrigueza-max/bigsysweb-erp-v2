<?php

namespace App\Services\Envios;

use App\Mail\DocumentoMail;
use App\Models\Business;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\OrdenCompra;
use App\Models\Pago;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// Envío de documentos por mail (con PDF) y WhatsApp (API oficial si hay credenciales; si no, link listo para mandar a mano).
class EnvioService
{
    // Devuelve [html, nombreArchivo] del PDF de cada documento, reusando las vistas de impresión.
    public function pdf(object $m): array
    {
        [$vista, $datos, $nombre] = match (true) {
            $m instanceof Comprobante && $m->direccion === 'venta' => ['comprobantes.imprimir', ['c' => $m->load('items', 'contact', 'business'), 'b' => $m->business], "{$m->nombreTipo()} {$m->numeroFormateado()}.pdf"],
            $m instanceof Cobro => ['comprobantes.recibo', ['cobro' => $m->load('contact', 'medios', 'imputaciones.comprobante', 'business'), 'b' => $m->business], "Recibo {$m->numeroFormateado()}.pdf"],
            $m instanceof Pago => ['comprobantes.orden_pago', ['p' => $m->load('contact', 'medios.cheque', 'imputaciones.comprobante', 'business'), 'b' => $m->business], "Orden de pago {$m->numeroFormateado()}.pdf"],
            $m instanceof OrdenCompra => ['compras.orden', ['oc' => $m->load('items.product', 'contact', 'business', 'location'), 'b' => $m->business], "{$m->numeroFormateado()}.pdf"],
            $m instanceof Contact => ['fichas.cuenta', $this->datosFicha($m), "Resumen de cuenta {$m->name}.pdf"],
            default => throw new \InvalidArgumentException('Documento no soportado'),
        };
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($vista, $datos)->setPaper('a4');
        return [$pdf->output(), str_replace(['/', '\\'], '-', $nombre)];
    }

    public function datosFicha(Contact $c): array
    {
        $movs = \App\Models\CuentaCorriente::where('contact_id', $c->id)->orderBy('fecha')->orderBy('id')->get();
        $saldo = 0; $filas = [];
        foreach ($movs as $m) { $saldo += (float) $m->debe - (float) $m->haber; $filas[] = ['fecha' => $m->fecha->format('d/m/Y'), 'concepto' => $m->concepto, 'debe' => (float) $m->debe, 'haber' => (float) $m->haber, 'saldo' => round($saldo, 2), 'vto' => $m->fecha_vto?->format('d/m/Y')]; }
        $pend = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->get();
        return ['c' => $c, 'b' => $c->business, 'filas' => array_slice($filas, -40), 'saldo' => round($saldo, 2), 'pendientes' => $pend, 'vencido' => $pend->filter(fn($p) => $p->vencido())->sum('saldo')];
    }

    // Texto corto para el cuerpo del mail / WhatsApp, con link público cuando aplica.
    public function texto(object $m, Business $b): array
    {
        $firma = "\n\n{$b->name}" . ($b->phone ? " · {$b->phone}" : '');
        return match (true) {
            $m instanceof Comprobante && $m->tipo === 'PRE' => ['asunto' => "Presupuesto {$m->numeroFormateado()} de {$b->name}", 'cuerpo' => "Hola {$m->contact?->name}, te mandamos el presupuesto {$m->numeroFormateado()} por $ " . number_format((float) $m->total, 2, ',', '.') . ".\nPodés verlo y aprobarlo acá: {$m->urlPublica()}" . $firma],
            $m instanceof Comprobante && $m->esFactura() => ['asunto' => "{$m->nombreTipo()} {$m->numeroFormateado()} de {$b->name}", 'cuerpo' => "Hola {$m->contact?->name}, adjuntamos la {$m->nombreTipo()} {$m->numeroFormateado()} por $ " . number_format((float) $m->total, 2, ',', '.') . ($m->fecha_vto && $m->condicion === 'cta_cte' ? " con vencimiento {$m->fecha_vto->format('d/m/Y')}" : '') . ".\nVerla online: {$m->urlPublica()}" . ($m->link_pago ? "\nPagar ahora: {$m->link_pago}" : '') . $firma],
            $m instanceof Comprobante => ['asunto' => "{$m->nombreTipo()} {$m->numeroFormateado()} de {$b->name}", 'cuerpo' => "Hola {$m->contact?->name}, te enviamos {$m->nombreTipo()} {$m->numeroFormateado()}.\nVerla online: {$m->urlPublica()}" . $firma],
            $m instanceof Cobro => ['asunto' => "Recibo {$m->numeroFormateado()} de {$b->name}", 'cuerpo' => "Hola {$m->contact?->name}, recibimos tu pago de $ " . number_format((float) $m->total, 2, ',', '.') . ". Adjuntamos el recibo {$m->numeroFormateado()}. ¡Gracias!" . $firma],
            $m instanceof Pago => ['asunto' => "Orden de pago {$m->numeroFormateado()} de {$b->name}", 'cuerpo' => "Hola {$m->contact?->name}, te enviamos la orden de pago {$m->numeroFormateado()} por $ " . number_format((float) $m->total, 2, ',', '.') . "." . $firma],
            $m instanceof OrdenCompra => ['asunto' => "Orden de compra {$m->numeroFormateado()} de {$b->name}", 'cuerpo' => "Hola {$m->contact?->name}, te pasamos la orden de compra {$m->numeroFormateado()}:\n" . $m->items->map(fn($i) => "• " . rtrim(rtrim(number_format((float) $i->cantidad, 3, ',', '.'), '0'), ',') . " {$i->descripcion}")->implode("\n") . ($m->fecha_entrega ? "\nEntrega esperada: {$m->fecha_entrega->format('d/m/Y')}" : '') . $firma],
            $m instanceof Contact => ['asunto' => "Resumen de cuenta de {$b->name}", 'cuerpo' => "Hola {$m->name}, adjuntamos tu resumen de cuenta. Saldo actual: $ " . number_format((float) $m->balance, 2, ',', '.') . "." . $firma],
            default => ['asunto' => "Documento de {$b->name}", 'cuerpo' => $firma],
        };
    }

    // canal: mail | whatsapp. Devuelve el Envio (con link para WhatsApp manual).
    public function enviar(object $m, string $canal, ?string $destino, string $tipo, ?string $mensaje = null, bool $adjuntar = true): Envio
    {
        $user = Auth::user(); $b = $user->business;
        $contact = $m instanceof Contact ? $m : ($m->contact ?? null);
        $t = $this->texto($m, $b);
        $cuerpo = $mensaje ?: $t['cuerpo'];
        $envio = Envio::create(['business_id' => $b->id, 'user_id' => $user->id, 'contact_id' => $contact?->id, 'modelo' => class_basename($m), 'modelo_id' => $m->getKey(), 'canal' => $canal, 'tipo' => $tipo, 'destino' => $destino, 'asunto' => $t['asunto'], 'cuerpo' => $cuerpo, 'estado' => 'pendiente']);
        try {
            if ($canal === 'mail') {
                if (! $destino) throw new \RuntimeException('El contacto no tiene email.');
                $adj = $adjuntar ? $this->pdf($m) : null;
                Mail::to($destino)->send(new DocumentoMail($b, $t['asunto'], $cuerpo, $adj));
                $envio->update(['estado' => 'enviado', 'enviado_en' => now()]);
            } else {
                $tel = preg_replace('/\D/', '', (string) $destino);
                if (! $tel) throw new \RuntimeException('El contacto no tiene teléfono.');
                if (! str_starts_with($tel, '54')) $tel = '54' . ltrim($tel, '0');
                $ws = $b->whatsapp_settings ?? [];
                if (! empty($ws['token']) && ! empty($ws['phone_id'])) {
                    $r = Http::withToken($ws['token'])->timeout(10)->post("https://graph.facebook.com/v20.0/{$ws['phone_id']}/messages", ['messaging_product' => 'whatsapp', 'to' => $tel, 'type' => 'text', 'text' => ['body' => $cuerpo]]);
                    if (! $r->ok()) throw new \RuntimeException('WhatsApp API: ' . $r->body());
                    $envio->update(['estado' => 'enviado', 'enviado_en' => now(), 'link' => "https://wa.me/{$tel}"]);
                } else {
                    // Sin API: queda el link listo para abrir WhatsApp Web con el texto cargado.
                    $envio->update(['estado' => 'pendiente', 'link' => "https://wa.me/{$tel}?text=" . rawurlencode($cuerpo)]);
                }
            }
        } catch (\Throwable $e) {
            $envio->update(['estado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        return $envio->fresh();
    }

    public function marcarEnviado(Envio $e): void { $e->update(['estado' => 'enviado', 'enviado_en' => now()]); }
}
