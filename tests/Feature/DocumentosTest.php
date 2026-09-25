<?php

namespace Tests\Feature;

use App\Models\Documento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 26.6: documentos adjuntos a clientes, proveedores y artículos.
class DocumentosTest extends ErpTestCase
{
    public function test_adjuntar_listar_bajar_y_quitar(): void
    {
        Storage::fake('local');
        $cli = $this->cliente(); $p = $this->articulo();
        $this->post("/documentos/contacto/{$cli->id}", ['archivo' => UploadedFile::fake()->create('contrato.pdf', 120, 'application/pdf'), 'notas' => 'Firmado'])->assertOk()->assertJsonPath('nombre', 'contrato.pdf');
        $this->post("/documentos/articulo/{$p->id}", ['archivo' => UploadedFile::fake()->image('foto.jpg')])->assertOk()->assertJsonPath('imagen', true);
        $this->postJson("/documentos/contacto/{$cli->id}", ['archivo' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $lista = $this->getJson("/documentos/contacto/{$cli->id}")->assertOk()->assertJsonCount(1)->json();
        $doc = Documento::find($lista[0]['id']);
        Storage::disk('local')->assertExists($doc->archivo);
        $this->get($lista[0]['url'])->assertOk()->assertDownload('contrato.pdf');
        $this->deleteJson("/documentos/{$doc->id}")->assertOk();
        Storage::disk('local')->assertMissing($doc->archivo);
        $this->assertSame(1, Documento::count());
    }

    public function test_otra_empresa_no_ve_ni_baja_los_documentos(): void
    {
        Storage::fake('local');
        $cli = $this->cliente();
        $id = $this->post("/documentos/contacto/{$cli->id}", ['archivo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->json('id');
        [, , $otro] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($otro);
        $this->getJson("/documentos/contacto/{$cli->id}")->assertNotFound();
        $this->get("/documentos/{$id}/descargar")->assertNotFound();
        $this->deleteJson("/documentos/{$id}")->assertNotFound();
    }
}
