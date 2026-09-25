<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\AfipEmisor;
use Tests\ErpTestCase;

// Fase 26.5: el cliente se carga en la misma factura; se crea o se actualiza en Clientes, o queda solo en el comprobante.
class ClienteDesdeFacturaTest extends ErpTestCase
{
    private function datos(array $receptor, array $extra = []): array
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        return ['tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'receptor' => $receptor, 'items' => [['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 100, 'alicuota_iva' => 21]]] + $extra;
    }

    public function test_crea_el_cliente_y_la_segunda_vez_lo_encuentra_por_documento_y_actualiza(): void
    {
        $antes = Contact::customers()->count();
        $this->post('/comprobantes', $this->datos(['nombre' => 'Juan Pérez', 'documento' => '30111222', 'address' => 'San Martín 100', 'guardar' => true]))->assertSessionHasNoErrors();
        $c = Contact::where('document', '30111222')->first();
        $this->assertNotNull($c); $this->assertSame('Juan Pérez', $c->name); $this->assertSame('Consumidor Final', $c->condicion_iva);
        $this->assertSame($c->id, Comprobante::latest('id')->first()->contact_id);
        $this->post('/comprobantes', $this->datos(['nombre' => 'Juan Pérez', 'documento' => '30.111.222', 'email' => 'juan@mail.com', 'guardar' => true]))->assertSessionHasNoErrors();
        $this->assertSame($antes + 1, Contact::customers()->count(), 'No duplica: lo encontró por DNI');
        $this->assertSame('juan@mail.com', $c->fresh()->email); $this->assertSame('San Martín 100', $c->fresh()->address, 'Lo que no se cargó no se borra');
    }

    public function test_con_cuit_de_responsable_inscripto_sale_factura_a(): void
    {
        $this->post('/comprobantes', $this->datos(['nombre' => 'Empresa SA', 'documento' => '20-12345678-6', 'condicion_iva' => 'Responsable Inscripto', 'guardar' => true]))->assertSessionHasNoErrors();
        $f = Comprobante::latest('id')->first();
        $this->assertSame('FA', $f->tipo);
        $this->assertSame('20-12345678-6', $f->contact->cuit);
    }

    public function test_sin_guardar_los_datos_quedan_en_la_factura_y_van_a_arca_como_dni(): void
    {
        $antes = Contact::count();
        $this->post('/comprobantes', $this->datos(['nombre' => 'Ana Gómez', 'documento' => '25111333', 'guardar' => false]))->assertSessionHasNoErrors();
        $f = Comprobante::latest('id')->first();
        $this->assertSame($antes, Contact::count()); $this->assertNull($f->contact_id);
        $this->assertSame('Ana Gómez', $f->receptor['nombre']);
        $d = app(AfipEmisor::class)->armarDatos($f->fresh(['items', 'contact', 'impuestos']), 6, 1);
        $this->assertSame(96, $d['DocTipo']); $this->assertSame(25111333, $d['DocNro']);
        $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->assertSee('Ana Gómez')->assertSee('25111333');
    }

    public function test_documento_invalido_se_rechaza(): void
    {
        $this->post('/comprobantes', $this->datos(['nombre' => 'X', 'documento' => '123']))->assertSessionHasErrors('receptor.documento');
        $this->post('/comprobantes', $this->datos(['nombre' => 'X', 'documento' => '20-12345678-0']))->assertSessionHasErrors('receptor.documento');
    }
}
