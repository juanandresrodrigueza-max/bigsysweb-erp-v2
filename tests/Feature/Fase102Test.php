<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Afip\InvoiceService;
use App\Services\Comprobantes\AfipEmisor;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 102 · La letra del comprobante según la condición de IVA del emisor y del receptor, como lo pide ARCA (RG 1415, RG 5003, RG 5616, RG 5700).
class Fase102Test extends ErpTestCase
{
    public function test_la_letra_sale_de_las_dos_condiciones_de_iva(): void
    {
        $ri = 'Responsable Inscripto';
        // Emisor responsable inscripto
        $this->assertSame('A', Contact::letraSegun($ri, 'Responsable Inscripto'));
        $this->assertSame('A', Contact::letraSegun($ri, 'Monotributista'), 'RG 5003: al monotributista se le factura A desde el 1/7/2021');
        $this->assertSame('A', Contact::letraSegun($ri, 'Monotributista Social'));
        $this->assertSame('B', Contact::letraSegun($ri, 'Exento'));
        $this->assertSame('B', Contact::letraSegun($ri, 'Consumidor Final'));
        $this->assertSame('B', Contact::letraSegun($ri, 'No Categorizado'));
        $this->assertSame('B', Contact::letraSegun($ri, 'IVA No Alcanzado'));
        $this->assertSame('B', Contact::letraSegun($ri, 'No Responsable'));
        $this->assertSame('B', Contact::letraSegun($ri, null));
        $this->assertSame('E', Contact::letraSegun($ri, 'Exterior'));
        // Emisor monotributista o exento: C a todos, E al exterior
        foreach (['Monotributista', 'Exento'] as $emisor) {
            foreach (['Responsable Inscripto', 'Monotributista', 'Exento', 'Consumidor Final', null] as $rec) $this->assertSame('C', Contact::letraSegun($emisor, $rec), "{$emisor} → {$rec}");
            $this->assertSame('E', Contact::letraSegun($emisor, 'Exterior'));
        }
        // La leyenda de la ley 27.618 sólo en la A al monotributista.
        $this->assertSame(Contact::LEYENDA_MONOTRIBUTO, Contact::leyendaPara('A', 'Monotributista'));
        $this->assertNull(Contact::leyendaPara('A', 'Responsable Inscripto')); $this->assertNull(Contact::leyendaPara('B', 'Consumidor Final')); $this->assertNull(Contact::leyendaPara('C', 'Monotributista'));
        // La API vieja usa la misma regla.
        $svc = app(InvoiceService::class);
        $this->assertSame(1, $svc->resolveVoucherType($this->empresa, $this->cliente(['name' => 'Mono API', 'condicion_iva' => 'Monotributista', 'cuit' => '20-11111111-2'])));
        $this->assertSame(6, $svc->resolveVoucherType($this->empresa, $this->cliente(['name' => 'CF API', 'condicion_iva' => 'Consumidor Final', 'cuit' => null])));
        $this->assertContains('No Categorizado', Contact::CONDICIONES_IVA); $this->assertContains('Monotributista Social', Contact::CONDICIONES_IVA);
    }

    public function test_factura_a_al_monotributista_con_condicion_6_y_leyenda_en_el_pdf(): void
    {
        $p = $this->articulo(['stock_inicial' => 10, 'alicuota_iva' => 21]);
        $mono = $this->cliente(['name' => 'Kiosco Mono', 'condicion_iva' => 'Monotributista', 'cuit' => '20-22222222-3']);
        $f = $this->factura($mono, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $this->assertSame('FA', $f->tipo); $this->assertEqualsWithDelta(210, (float) $f->iva, 0.01, 'La A discrimina el IVA también al monotributista');
        $d = app(AfipEmisor::class)->armarDatos($f->fresh(['items', 'contact', 'impuestos']), 1, 1);
        $this->assertSame(6, $d['CondicionIVAReceptorId']); $this->assertSame(80, $d['DocTipo']); $this->assertSame(20222222223, $d['DocNro']);
        $this->assertSame(Contact::LEYENDA_MONOTRIBUTO, $f->leyendaLegal());
        $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->assertSee('Ley Nº 27.618');
        // La nota de crédito de esa factura también es A.
        $nc = $this->factura($mono, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['tipo' => 'NCX']);
        $this->assertSame('NCA', $nc->tipo);
        // Exento con CUIT: B con condición 4. Receptor cargado en la factura sin cliente guardado: manda su condición.
        $ex = $this->cliente(['name' => 'Fundación', 'condicion_iva' => 'Exento', 'cuit' => '30-33333333-4']);
        $fb = $this->factura($ex, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $this->assertSame('FB', $fb->tipo); $this->assertSame(4, app(AfipEmisor::class)->armarDatos($fb->fresh(['items', 'contact', 'impuestos']), 6, 2)['CondicionIVAReceptorId']); $this->assertNull($fb->leyendaLegal());
        $this->get("/comprobantes/{$fb->id}/imprimir")->assertOk()->assertDontSee('27.618');
    }

    public function test_lo_que_arca_rechaza_se_frena_antes_con_un_mensaje_claro(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        // Inscripto sin CUIT: se factura como consumidor final (B); y si alguien fuerza la A, no se emite.
        $sinCuit = $this->cliente(['name' => 'Inscripto sin CUIT', 'condicion_iva' => 'Responsable Inscripto', 'cuit' => null]);
        $b = $svc->guardarBorrador(['contact_id' => $sinCuit->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]]);
        $this->assertSame('FB', $b->tipo); $this->assertSame('Consumidor Final', $sinCuit->condicionEfectiva());
        $b->forceFill(['tipo' => 'FA'])->save();
        try { $svc->emitir($b->fresh()); $this->fail('Tenía que frenar'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('CUIT', $e->errors()['contact_id'][0]); }
        // Receptor cargado en la factura sin cliente guardado: con CUIT de 11 dígitos vale su condición; sin CUIT, consumidor final.
        $r1 = $svc->guardarBorrador(['contact_id' => null, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'receptor' => ['nombre' => 'Kiosco Suelto', 'documento' => '20-55555555-6', 'condicion_iva' => 'Monotributista', 'guardar' => false], 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]]);
        $this->assertSame('FA', $r1->tipo); $this->assertSame('Monotributista', $r1->condicionReceptor());
        $r2 = $svc->guardarBorrador(['contact_id' => null, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'receptor' => ['nombre' => 'Alguien', 'documento' => '25781816', 'condicion_iva' => 'Responsable Inscripto', 'guardar' => false], 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]]);
        $this->assertSame('FB', $r2->tipo); $this->assertSame('Consumidor Final', $r2->condicionReceptor());
        // Consumidor final por encima del tope sin DNI: no se emite; con DNI, sí (documento 96).
        config(['erp.facturacion.identificar_cf_desde' => 5000]);
        $cf = $this->cliente(['name' => 'Alguien', 'condicion_iva' => 'Consumidor Final', 'cuit' => null]);
        $b2 = $svc->guardarBorrador(['contact_id' => $cf->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]]]);
        try { $svc->emitir($b2); $this->fail('Tenía que frenar'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('DNI', $e->errors()['contact_id'][0]); }
        $cf->update(['document' => '25781816']);
        $f = $svc->emitir($b2->fresh()); $this->assertSame('emitido', $f->estado);
        $d = app(AfipEmisor::class)->armarDatos($f->fresh(['items', 'contact', 'impuestos']), 6, 3); $this->assertSame(96, $d['DocTipo']); $this->assertSame(5, $d['CondicionIVAReceptorId']);
        // Por debajo del tope, el consumidor final anónimo sigue saliendo (documento 99).
        $chica = $this->factura($this->cliente(['name' => 'Anónimo', 'condicion_iva' => 'Consumidor Final', 'cuit' => null]), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('FB', $chica->tipo); $this->assertSame(99, app(AfipEmisor::class)->armarDatos($chica->fresh(['items', 'contact', 'impuestos']), 6, 4)['DocTipo']);
        // El monotributista emite C a todos, sin pedir nada raro.
        $this->empresa->update(['condicion_iva' => 'Monotributista']); Auth::login($this->dueno->fresh()); $this->actingAs($this->dueno->fresh());
        $c = $this->factura($this->cliente(['name' => 'Empresa grande', 'condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-44444444-5']), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('FC', $c->tipo); $this->assertSame(1, app(AfipEmisor::class)->armarDatos($c->fresh(['items', 'contact', 'impuestos']), 11, 1)['CondicionIVAReceptorId']);
    }
}
