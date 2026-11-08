<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Documento;
use App\Services\IA\DocumentosIaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 59: los documentos adjuntos se leen (PDF, Word, planilla, texto, foto) y se les pregunta en castellano; Bigcito responde con la fuente.
class DocumentosPreguntasFase59Test extends ErpTestCase
{
    private function docx(string $texto): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $parrafos = implode('', array_map(fn($l) => '<w:p><w:r><w:t>' . htmlspecialchars($l) . '</w:t></w:r></w:p>', explode("\n", $texto)));
        $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $parrafos . '</w:body></w:document>');
        $z->close(); return $tmp;
    }

    public function test_los_adjuntos_quedan_leidos_y_se_les_pregunta_con_fuente(): void
    {
        Storage::fake('local');
        $cli = $this->cliente(['name' => 'Ferretería López']);
        $this->post("/documentos/contacto/{$cli->id}", ['archivo' => UploadedFile::fake()->createWithContent('contrato-lopez.txt', "CONTRATO DE SUMINISTRO\nEntre Ferretería López y la empresa.\nPlazo: 24 meses desde el 01/03/2026. Vence el 28/02/2028.\nMora: interés del 3% mensual sobre saldos vencidos.\nEntrega: dentro de los 5 días hábiles del pedido."), 'nombre' => 'Contrato López'])->assertOk()->assertJsonPath('leido', true);
        $docx = $this->docx("Lista de precios noviembre\nCaño 4 m: \$ 12.500\nCodo 90: \$ 1.200");
        $this->post("/documentos/contacto/{$cli->id}", ['archivo' => new UploadedFile($docx, 'lista.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true)])->assertOk()->assertJsonPath('leido', true);
        $this->assertStringContainsString('Caño 4 m: $ 12.500', Documento::where('nombre', 'lista.docx')->value('texto'));

        // Sin clave de IA: los pedazos que coinciden, nada más.
        $r = $this->postJson('/documentos/preguntar', ['pregunta' => '¿cuándo vence el contrato?', 'tipo' => 'contacto', 'id' => $cli->id])->assertOk()->json();
        $this->assertNull($r['respuesta']); $this->assertFalse($r['ia']); $this->assertSame('Contrato López', $r['fuentes'][0]['nombre']); $this->assertStringContainsString('28/02/2028', $r['fuentes'][0]['fragmento']);
        $this->assertSame('Ferretería López', $r['fuentes'][0]['de']);

        // Con clave: la IA responde sólo con los fragmentos y cita la fuente.
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "Vence el 28/02/2028 (24 meses desde el 01/03/2026).\nFuente: Contrato López"]]])]);
        $r = $this->postJson('/documentos/preguntar', ['pregunta' => '¿cuándo vence el contrato?', 'tipo' => 'contacto', 'id' => $cli->id])->assertOk()->json();
        $this->assertStringContainsString('28/02/2028', $r['respuesta']); $this->assertTrue($r['ia']);
        $pedido = Http::recorded()[0][0]->data();
        $this->assertStringContainsString('Vence el 28', $pedido['messages'][0]['content']); $this->assertStringContainsString('Contrato López', $pedido['messages'][0]['content']);

        // La pregunta sobre el precio encuentra la lista, no el contrato.
        $f = app(DocumentosIaService::class)->buscar($this->empresa, 'precio del caño de 4 m', \App\Models\Contact::class, $cli->id);
        $this->assertSame('lista.docx', $f[0]['nombre']);

        // Otra empresa no ve nada.
        [$b2] = $this->crearEmpresa('Otra', 'otra');
        $this->assertSame([], app(DocumentosIaService::class)->buscar($b2, 'contrato vence'));
    }

    public function test_bigcito_busca_en_los_documentos_del_proveedor_y_los_viejos_se_leen_solos(): void
    {
        Storage::fake('local');
        $prov = $this->proveedor(['name' => 'Acindar SA']);
        // Un documento cargado "antes" de la función: sin texto ni indexado_en.
        Storage::disk('local')->put("documentos/{$this->empresa->id}/viejo.txt", "Condiciones comerciales Acindar\nPlazo de entrega: 10 días hábiles.\nPago: 30 días fecha factura.");
        Documento::create(['business_id' => $this->empresa->id, 'user_id' => $this->dueno->id, 'adjuntable_type' => \App\Models\Contact::class, 'adjuntable_id' => $prov->id, 'nombre' => 'Condiciones Acindar', 'archivo' => "documentos/{$this->empresa->id}/viejo.txt", 'mime' => 'text/plain', 'tamano' => 90, 'fecha' => '2026-01-10']);

        $r = app(Catalogo::class)->llamar($this->dueno, 'preguntar_documentos', ['pregunta' => 'plazo de entrega', 'proveedor' => 'Acindar'], 'asistente');
        $this->assertCount(1, $r['fragmentos']); $this->assertSame('Condiciones Acindar', $r['fragmentos'][0]['documento']); $this->assertStringContainsString('10 días hábiles', $r['fragmentos'][0]['texto']);
        $this->assertNotNull(Documento::first()->indexado_en, 'Se leyó solo al preguntar');

        $r = app(Catalogo::class)->llamar($this->dueno, 'preguntar_documentos', ['pregunta' => 'garantía del motor'], 'asistente');
        $this->assertSame([], $r['fragmentos']); $this->assertStringContainsString('No inventes', $r['nota']);
    }

    public function test_una_foto_se_lee_con_la_ia_y_sin_clave_espera(): void
    {
        Storage::fake('local');
        $art = $this->articulo(['name' => 'Bomba 1 HP']);
        $this->post("/documentos/articulo/{$art->id}", ['archivo' => UploadedFile::fake()->image('etiqueta.jpg', 40, 40)])->assertOk()->assertJsonPath('leido', false);
        $this->assertNull(Documento::first()->indexado_en, 'Sin clave queda pendiente para cuando la haya');

        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => "BOMBA CENTRÍFUGA 1 HP\nCaudal máximo: 60 l/min\nGarantía: 12 meses"]]])
            ->push(['content' => [['type' => 'text', 'text' => "Caudal máximo 60 l/min.\nFuente: etiqueta.jpg"]]])]);
        $r = $this->postJson('/documentos/preguntar', ['pregunta' => '¿qué caudal tiene?', 'tipo' => 'articulo', 'id' => $art->id])->assertOk()->json();
        $this->assertStringContainsString('60 l/min', $r['respuesta']);
        $this->assertStringContainsString('Caudal máximo: 60', Documento::first()->texto);
        $this->assertSame('image', Http::recorded()[0][0]->data()['messages'][0]['content'][0]['type']);
    }
}
