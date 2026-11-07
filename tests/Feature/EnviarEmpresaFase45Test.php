<?php

namespace Tests\Feature;

use App\Mail\DocumentoMail;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 45.2: el estudio le manda a la empresa su resumen del mes, en PDF o en el cuerpo del mail, con su marca.
class EnviarEmpresaFase45Test extends ErpTestCase
{
    public function test_envia_pdf_y_recuerda_el_mail(): void
    {
        Mail::fake();
        $this->factura($this->cliente(), [['product_id' => $this->articulo()->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);
        $mes = now()->format('Y-m');
        $this->post("/contador/empresas/{$this->empresa->id}/enviar", ['email' => 'duenio@empresa.com', 'formato' => 'pdf', 'mes' => $mes, 'mensaje' => 'Hola'])->assertSessionHasNoErrors();
        Mail::assertSent(DocumentoMail::class, fn($m) => $m->hasTo('duenio@empresa.com') && $m->adjunto && str_starts_with($m->adjunto[0], '%PDF') && str_contains($m->cuerpo, 'Ventas'));
        $this->get('/contador/empresas')->assertInertia(fn($p) => $p->where('empresas.0.email_envio', 'duenio@empresa.com')->etc());

        $this->post("/contador/empresas/{$this->empresa->id}/enviar", ['email' => 'otro@empresa.com', 'formato' => 'resumen', 'mes' => $mes])->assertSessionHasNoErrors();
        Mail::assertSent(DocumentoMail::class, fn($m) => $m->hasTo('otro@empresa.com') && ! $m->adjunto);
    }

    public function test_no_envia_de_una_empresa_ajena(): void
    {
        Mail::fake();
        [$otra] = $this->crearEmpresa('Ajena', 'ajena');
        $this->post("/contador/empresas/{$otra->id}/enviar", ['email' => 'x@y.com', 'formato' => 'pdf', 'mes' => now()->format('Y-m')])->assertForbidden();
        Mail::assertNothingSent();
    }
}
