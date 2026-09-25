<?php

namespace Tests\Feature;

use App\Models\ContactoPersona;
use Tests\ErpTestCase;

// Fase 26.3: varias personas de contacto por cliente o proveedor y a quién le llega cada documento.
class ContactoPersonasTest extends ErpTestCase
{
    public function test_contactos_por_cliente_y_el_envio_sugiere_a_quien_corresponde(): void
    {
        $cli = $this->cliente(['email' => 'general@cliente.com']);
        $this->postJson("/contactos/{$cli->id}/personas", ['nombre' => 'Ana Compras', 'cargo' => 'Compras', 'email' => 'ana@cliente.com', 'recibe_comprobantes' => true])->assertOk();
        $this->postJson("/contactos/{$cli->id}/personas", ['nombre' => 'Luis Pagos', 'cargo' => 'Tesorería', 'email' => 'luis@cliente.com', 'telefono' => '2615551234', 'recibe_cobranzas' => true])->assertOk();
        $this->postJson("/contactos/{$cli->id}/personas", ['nombre' => 'Sin mail', 'email' => 'no-es-mail'])->assertStatus(422);
        $this->getJson("/contactos/{$cli->id}/personas")->assertOk()->assertJsonCount(2);

        // Resumen de cuenta: va a quien recibe cobranzas.
        $r = $this->getJson('/envios/borrador?modelo=Contact&id=' . $cli->id)->assertOk()->json();
        $this->assertSame('luis@cliente.com', $r['email']); $this->assertSame('Luis Pagos', $r['personas'][0]['nombre']); $this->assertTrue($r['personas'][0]['sugerido']);
        // Factura: a quien recibe comprobantes.
        $p = $this->articulo(['stock_inicial' => 5]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('ana@cliente.com', $this->getJson('/envios/borrador?modelo=Comprobante&id=' . $f->id)->json('email'));

        // Editar y quitar.
        $ana = ContactoPersona::where('nombre', 'Ana Compras')->first();
        $this->postJson("/contactos/{$cli->id}/personas/{$ana->id}", ['nombre' => 'Ana Compras', 'cargo' => 'Jefa de compras', 'email' => 'ana@cliente.com', 'recibe_comprobantes' => false])->assertOk()->assertJsonPath('cargo', 'Jefa de compras');
        $this->assertSame('general@cliente.com', $this->getJson('/envios/borrador?modelo=Comprobante&id=' . $f->id)->json('email'), 'Sin nadie marcado, va al mail del cliente');
        $this->deleteJson("/contactos/{$cli->id}/personas/{$ana->id}")->assertOk();
        $this->assertSame(1, ContactoPersona::count());
    }

    public function test_otra_empresa_no_ve_ni_toca_los_contactos(): void
    {
        $cli = $this->cliente();
        $this->postJson("/contactos/{$cli->id}/personas", ['nombre' => 'Ana'])->assertOk();
        [, , $otro] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($otro);
        $this->getJson("/contactos/{$cli->id}/personas")->assertNotFound();
        $this->postJson("/contactos/{$cli->id}/personas", ['nombre' => 'Intruso'])->assertNotFound();
    }
}
