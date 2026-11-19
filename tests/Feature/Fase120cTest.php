<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\CompraBorrador;
use App\Models\Comprobante;
use App\Models\FacturaEntrante;
use App\Models\SistemaConfig;
use App\Services\Compras\BandejaFacturasService;
use App\Services\Correo\ImapCliente;
use App\Services\Correo\Mime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 120.3: la casilla de facturas. Lo que llega por mail (o se sube de a varias) se lee y queda a medio cargar.
class Fase120cTest extends ErpTestCase
{
    private function pdf(string $marca = 'A'): string { return "%PDF-1.4\n% factura {$marca}\n" . str_repeat("0123456789 {$marca}\n", 40) . "%%EOF"; }

    private function correo(array $o = []): string
    {
        $de = $o['de'] ?? 'ventas@acero.com'; $para = $o['para'] ?? 'facturas@bigsysweb.com'; $asunto = $o['asunto'] ?? 'Factura';
        $adj = $o['adjuntos'] ?? [['nombre' => 'factura.pdf', 'mime' => 'application/pdf', 'contenido' => $this->pdf()]];
        $b = 'limite-' . md5($asunto . $de);
        $m = "From: Ventas <{$de}>\r\nTo: {$para}\r\nSubject: {$asunto}\r\nMessage-ID: <" . ($o['id'] ?? md5(json_encode([$de, $para, $asunto, count($adj)]))) . "@acero.com>\r\n" . ($o['cabeceras'] ?? '')
            . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed;\r\n boundary=\"{$b}\"\r\n\r\nPreámbulo que no importa\r\n--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nAdjunto la factura del d=C3=ADa.\r\n";
        foreach ($adj as $a) {
            $m .= "--{$b}\r\nContent-Type: {$a['mime']}; name=\"{$a['nombre']}\"\r\nContent-Disposition: " . ($a['disp'] ?? 'attachment') . "; filename=\"{$a['nombre']}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($a['contenido'])) . "\r\n";
        }
        return $m . "--{$b}--\r\n";
    }

    // Lo que contesta la IA al leer la factura.
    private function iaLee(array $o = []): void
    {
        $json = ['proveedor' => ['nombre' => $o['nombre'] ?? 'Acero SA', 'cuit' => $o['cuit'] ?? '30-50000000-1', 'condicion_iva' => 'Responsable Inscripto'], 'tipo' => 'FA', 'numero' => $o['numero'] ?? '0003-00012345', 'fecha' => '2026-11-10', 'fecha_vto' => null, 'cae' => '74123456789012',
            'items' => [['product_id' => null, 'descripcion' => 'Hierro del 8', 'cantidad' => 10, 'precio_unit' => 1000, 'alicuota_iva' => 21, 'descuento' => 0]], 'neto' => 10000, 'iva' => 2100, 'percepciones' => [['tipo' => 'iibb_cba', 'monto' => 300]], 'total' => 12400, 'facturas_en_archivo' => 1, 'confianza' => 0.93, 'observaciones' => ''];
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($json)]]])]);
    }

    private function casilla(): void
    {
        SistemaConfig::set('casilla_host', 'imap.prueba.com'); SistemaConfig::set('casilla_usuario', 'facturas@bigsysweb.com'); SistemaConfig::set('casilla_clave', 'secreta');
    }

    private function comoProgramador(): void { Auth::logout(); $this->app["auth"]->forgetGuards(); }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
    }

    public function test_el_lector_de_correos_saca_adjuntos_nombres_raros_y_mails_reenviados(): void
    {
        $adentro = $this->correo(['asunto' => 'La original', 'adjuntos' => [['nombre' => 'foto.jpg', 'mime' => 'image/jpeg', 'contenido' => str_repeat('J', 50000)]]]);
        $b = 'ext-1';
        $crudo = "Received: de por ahi\r\nFrom: =?UTF-8?B?" . base64_encode('José Pérez') . "?= <Jose@Corralon.com>\r\nTo: \"Facturas\" <facturas+ab12cd34ef@bigsysweb.com>, otro@x.com\r\nDelivered-To: facturas@bigsysweb.com\r\n"
            . "Subject: =?ISO-8859-1?Q?Facturaci=F3n_de_noviembre?=\r\nMessage-ID: <abc@corralon.com>\r\nContent-Type: multipart/mixed; boundary={$b}\r\n\r\n"
            . "--{$b}\r\nContent-Type: text/plain; charset=ISO-8859-1\r\n\r\nVa la facturaci\xF3n.\r\n"
            . "--{$b}\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename*=UTF-8''Factura%20A%20n%C2%BA%2012.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($this->pdf())) . "\r\n"
            . "--{$b}\r\nContent-Type: image/png\r\nContent-ID: <logo>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode(str_repeat('L', 900)) . "\r\n"
            . "--{$b}\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment\r\n\r\n{$adentro}\r\n--{$b}--\r\nepílogo";
        $m = Mime::leer($crudo);
        $this->assertSame('jose@corralon.com', $m['de']); $this->assertSame('Facturación de noviembre', $m['asunto']); $this->assertSame('abc@corralon.com', $m['message_id']);
        $this->assertContains('facturas+ab12cd34ef@bigsysweb.com', $m['para']); $this->assertContains('facturas@bigsysweb.com', $m['para']); $this->assertFalse($m['automatico']);
        $this->assertStringContainsString('Va la facturación.', $m['texto']);
        $this->assertCount(3, $m['adjuntos']);
        $this->assertSame('Factura A nº 12.pdf', $m['adjuntos'][0]['nombre']); $this->assertSame('application/pdf', $m['adjuntos'][0]['mime']); $this->assertSame($this->pdf(), $m['adjuntos'][0]['contenido']);
        $this->assertSame('logo.png', $m['adjuntos'][1]['nombre']); $this->assertTrue($m['adjuntos'][1]['en_linea']);
        $this->assertSame('foto.jpg', $m['adjuntos'][2]['nombre']); $this->assertSame(50000, strlen($m['adjuntos'][2]['contenido']));
        // Una respuesta automática se reconoce; un mail sin partes también se lee.
        $this->assertTrue(Mime::leer("From: a@b.com\r\nAuto-Submitted: auto-replied\r\nSubject: Fuera de la oficina\r\n\r\nVuelvo el lunes.")['automatico']);
        $simple = Mime::leer("From: a@b.com\nSubject: Hola\nContent-Type: application/pdf; name=\"f.pdf\"\nContent-Transfer-Encoding: base64\n\n" . base64_encode($this->pdf('Z')));
        $this->assertSame($this->pdf('Z'), $simple['adjuntos'][0]['contenido']);
    }

    public function test_el_cliente_imap_baja_lo_no_leido_y_lo_marca(): void
    {
        $falsa = new CasillaFalsa([7 => "From: a@b.com\r\n\r\nUno {3}\r\ncon llaves", 9 => $this->correo()]);
        $imap = new ImapCliente($falsa);
        $imap->entrar('facturas@bigsysweb.com', 'cla"ve\\rara');
        $this->assertSame('B0001 LOGIN "facturas@bigsysweb.com" "cla\\"ve\\\\rara"', $falsa->enviado[0]);
        $this->assertSame([7, 9], $imap->sinLeer());
        $this->assertSame("From: a@b.com\r\n\r\nUno {3}\r\ncon llaves", $imap->mensaje(7)); // el mensaje se lee por largo, no por líneas
        $this->assertSame($this->correo(), $imap->mensaje(9));
        $imap->marcarLeido(7);
        $this->assertSame([9], $imap->sinLeer()); $this->assertSame([7], $falsa->vistos);
        $imap->salir();
        // Clave mal: avisa claro.
        $mala = new CasillaFalsa([], false);
        try { (new ImapCliente($mala))->entrar('u', 'p'); $this->fail('Tenía que rechazar'); } catch (\RuntimeException $e) { $this->assertStringContainsString('rechazó', $e->getMessage()); }
    }

    public function test_una_factura_que_llega_por_mail_queda_leida_a_medio_cargar_y_al_registrarla_se_cierra(): void
    {
        $this->casilla(); $this->iaLee();
        $prov = $this->proveedor(['name' => 'Acero SA']);
        $dir = BandejaFacturasService::direccion($this->empresa);
        $this->assertMatchesRegularExpression('/^facturas\+[a-z0-9]{10}@bigsysweb\.com$/', $dir);
        $this->assertSame($dir, BandejaFacturasService::direccion($this->empresa->fresh()), 'El código no cambia');

        $this->comoProgramador();
        $svc = app(BandejaFacturasService::class);
        $filas = $svc->procesarCorreo(Mime::leer($this->correo(['para' => $dir])));
        $this->assertCount(1, $filas); $e = $filas[0];
        $this->assertSame('lista', $e->estado); $this->assertSame($this->empresa->id, $e->business_id); $this->assertSame('mail', $e->origen); $this->assertSame('ventas@acero.com', $e->remitente);
        $this->assertSame('Acero SA', $e->proveedor); $this->assertSame('0003-00012345', $e->numero); $this->assertEqualsWithDelta(12400, $e->total, 0.01);
        Storage::disk('local')->assertExists($e->ruta);
        $this->assertNull(Auth::user(), 'No queda nadie logueado después de leer');

        $bor = CompraBorrador::withoutGlobalScopes()->findOrFail($e->borrador_id);
        $this->assertSame($prov->id, $bor->contact_id); $this->assertSame('0003-00012345', $bor->numero); $this->assertEqualsWithDelta(12100, $bor->total, 0.01);
        $d = $bor->datos;
        $this->assertSame('FA', $d['tipo']); $this->assertSame('ocr', $d['origen_carga']); $this->assertSame('2026-11-10', $d['fecha']); $this->assertNotEmpty($d['adjunto_id']);
        $this->assertSame('Hierro del 8', $d['items'][0]['descripcion']); $this->assertEqualsWithDelta(10, $d['items'][0]['cantidad'], 0.001);
        $this->assertSame('iibb', $d['impuestos'][0]['tipo']); $this->assertEqualsWithDelta(300, $d['impuestos'][0]['monto'], 0.01); $this->assertEqualsWithDelta(12400, $d['ocr']['totalLeido'], 0.01);
        $al = Alerta::withoutGlobalScopes()->where('tipo', 'factura_casilla')->first();
        $this->assertStringContainsString('Acero SA por mail', $al->titulo); $this->assertSame("/proveedores/compras/nueva?borrador={$bor->id}", $al->url);

        // El mismo mail otra vez no hace nada; el mismo archivo en otro mail queda como repetido.
        $this->assertSame([], $svc->procesarCorreo(Mime::leer($this->correo(['para' => $dir]))));
        $rep = $svc->procesarCorreo(Mime::leer($this->correo(['para' => $dir, 'id' => 'otro'])));
        $this->assertSame('duplicada', $rep[0]->estado); $this->assertStringContainsString('mismo archivo', $rep[0]->detalle);
        // Otro archivo con la misma factura (el proveedor la mandó dos veces): ya está a medio cargar.
        $rep2 = $svc->procesarCorreo(Mime::leer($this->correo(['para' => $dir, 'id' => 'tres', 'adjuntos' => [['nombre' => 'copia.pdf', 'mime' => 'application/pdf', 'contenido' => $this->pdf('B')]]])));
        $this->assertSame('duplicada', $rep2[0]->estado); $this->assertStringContainsString('a medio cargar', $rep2[0]->detalle);
        $this->assertSame(1, CompraBorrador::withoutGlobalScopes()->count());

        // La dueña entra a Compras, la ve, la retoma y la registra: la casilla la da por cargada.
        Auth::login($this->dueno); $this->actingAs($this->dueno);
        $r = $this->getJson('/proveedores/compras/bandeja')->assertOk()->json();
        $this->assertSame($dir, $r['direccion']); $this->assertSame(1, $r['por_revisar']);
        $fila = collect($r['lista'])->firstWhere('estado', 'lista'); $this->assertSame($bor->id, $fila['borrador_id']); $this->assertSame('Para revisar', $fila['estado_txt']);
        $this->get("/proveedores/compras/bandeja/{$e->id}/archivo")->assertOk();
        $retomada = $this->getJson("/proveedores/compras/borradores/{$bor->id}")->assertOk()->json('datos');
        $art = $this->articulo(['price' => 2000]);
        $this->post('/proveedores/compras', ['contact_id' => $retomada['contact_id'], 'tipo' => $retomada['tipo'], 'numero_proveedor' => $retomada['numero_proveedor'], 'fecha' => $retomada['fecha'], 'condicion' => 'cta_cte', 'origen_carga' => 'ocr', 'adjunto_id' => $retomada['adjunto_id'], 'borrador_id' => $bor->id,
            'items' => [['product_id' => $art->id, 'descripcion' => 'Hierro del 8', 'cantidad' => 10, 'precio_unit' => 1000, 'alicuota_iva' => 21]], 'impuestos' => $retomada['impuestos'], 'registrar' => true])->assertSessionHasNoErrors();
        $compra = Comprobante::compras()->where('numero_proveedor', '0003-00012345')->firstOrFail();
        $e->refresh(); $this->assertSame('cargada', $e->estado); $this->assertSame($compra->id, $e->compra_id);
        $this->assertSame(0, CompraBorrador::count());
        // Si vuelve a llegar la misma factura en otro archivo: ya está cargada.
        $rep3 = app(BandejaFacturasService::class)->recibir($this->empresa, $this->pdf('C'), 'application/pdf', 'otra-vez.pdf', ['origen' => 'subida'], $this->dueno);
        $this->assertSame('duplicada', $rep3->estado); $this->assertStringContainsString('ya está cargada', $rep3->detalle);
    }

    public function test_reconoce_la_empresa_por_el_codigo_el_usuario_o_el_proveedor_y_si_no_la_deja_sin_empresa(): void
    {
        $this->casilla(); $this->iaLee();
        [$otra, , $duenoOtra] = $this->crearEmpresa('Otra Empresa', 'otra');
        $otra->forceFill(['ia_settings' => ['api_key' => 'sk-otra', 'modelo' => 'claude-haiku-5-5']])->save();
        $this->proveedor(['name' => 'Acero SA', 'email' => 'Ventas@Acero.com']);
        $svc = app(BandejaFacturasService::class);
        $tok = BandejaFacturasService::token($otra);
        $this->comoProgramador();

        // 1) El código manda, aunque el remitente sea proveedor de otra empresa. También vale en el asunto.
        $this->assertSame($otra->id, $svc->empresaDe(['de' => 'ventas@acero.com', 'para' => ["facturas+{$tok}@bigsysweb.com"], 'asunto' => 'x'])->id);
        $this->assertSame($otra->id, $svc->empresaDe(['de' => 'nadie@x.com', 'para' => ['facturas@bigsysweb.com'], 'asunto' => "Fw: factura #{$tok}"])->id);
        // 2) La reenvía un usuario desde su mail.
        $this->assertSame($otra->id, $svc->empresaDe(['de' => strtoupper($duenoOtra->email), 'para' => ['facturas@bigsysweb.com'], 'asunto' => 'Fw'])->id);
        // 3) La manda el proveedor, que está cargado con ese mail en una sola empresa.
        $this->assertSame($this->empresa->id, $svc->empresaDe(['de' => 'ventas@acero.com', 'para' => ['facturas@bigsysweb.com'], 'asunto' => 'Factura'])->id);
        // Si el mismo mail de proveedor está en dos empresas, no se adivina.
        \App\Models\Contact::withoutGlobalScopes()->create(['business_id' => $otra->id, 'type' => 'supplier', 'name' => 'Acero', 'email' => 'ventas@acero.com', 'is_active' => true]);
        $this->assertNull($svc->empresaDe(['de' => 'ventas@acero.com', 'para' => ['facturas@bigsysweb.com'], 'asunto' => 'Factura']));

        $sin = $svc->procesarCorreo(Mime::leer($this->correo(['de' => 'desconocido@x.com'])));
        $this->assertSame('sin_empresa', $sin[0]->estado); $this->assertNull($sin[0]->business_id); $this->assertSame('factura.pdf', $sin[0]->archivo);
        // Sin factura adjunta (sólo la firma): queda anotado, no se lee nada. Los rebotes se ignoran.
        $firma = $svc->procesarCorreo(Mime::leer($this->correo(['para' => "facturas+{$tok}@bigsysweb.com", 'adjuntos' => [['nombre' => 'firma.png', 'mime' => 'image/png', 'contenido' => str_repeat('x', 5000), 'disp' => 'inline']]])));
        $this->assertSame('sin_adjunto', $firma[0]->estado); $this->assertSame($otra->id, $firma[0]->business_id);
        $this->assertSame([], $svc->procesarCorreo(Mime::leer($this->correo(['de' => 'MAILER-DAEMON@x.com', 'para' => "facturas+{$tok}@bigsysweb.com"]))));
        $this->assertSame([], $svc->procesarCorreo(Mime::leer($this->correo(['para' => "facturas+{$tok}@bigsysweb.com", 'id' => 'auto', 'cabeceras' => "Auto-Submitted: auto-replied\r\n"]))));
        Http::assertNothingSent();

        // Cada empresa ve sólo lo suyo.
        $svc->procesarCorreo(Mime::leer($this->correo(['para' => "facturas+{$tok}@bigsysweb.com", 'id' => 'de-otra'])));
        Auth::login($this->dueno); $this->actingAs($this->dueno);
        $this->assertSame([], $this->getJson('/proveedores/compras/bandeja')->json('lista'));
        $ajena = FacturaEntrante::withoutGlobalScopes()->where('business_id', $otra->id)->where('estado', 'lista')->firstOrFail();
        $this->get("/proveedores/compras/bandeja/{$ajena->id}/archivo")->assertNotFound();
        $this->deleteJson("/proveedores/compras/bandeja/{$ajena->id}")->assertNotFound();
    }

    public function test_el_programador_vacia_la_casilla_y_un_mail_roto_no_frena_al_resto(): void
    {
        $this->casilla(); $this->iaLee();
        $this->proveedor(['name' => 'Acero SA']);
        $dir = BandejaFacturasService::direccion($this->empresa);
        $this->comoProgramador();
        $falsa = new CasillaFalsa([3 => "esto no es un correo", 5 => $this->correo(['para' => $dir]), 8 => $this->correo(['de' => 'quien@sabe.com', 'id' => 'x8'])]);
        $imap = new ImapCliente($falsa); $imap->entrar('u', 'p');
        $r = app(BandejaFacturasService::class)->revisar($imap);
        $this->assertSame(['correos' => 3, 'facturas' => 1, 'sin_empresa' => 2, 'error' => null], $r);
        $this->assertSame([3, 5, 8], $falsa->vistos);
        $this->assertSame(1, FacturaEntrante::withoutGlobalScopes()->where('estado', 'lista')->count());
        // Sin casilla configurada el comando no hace nada y no rompe.
        SistemaConfig::set('casilla_host', null);
        $this->artisan('casilla:revisar')->expectsOutputToContain('no está configurada')->assertSuccessful();
        $this->assertNull(BandejaFacturasService::direccion($this->empresa));
    }

    public function test_subir_varias_desde_compras_quitar_y_cuando_la_ia_no_puede_leer(): void
    {
        $this->iaLee(['nombre' => 'Maderas del Sur', 'cuit' => '30-70000000-9', 'numero' => '0001-00000077']);
        $r = $this->post('/proveedores/compras/bandeja', ['archivo' => UploadedFile::fake()->createWithContent('remito.pdf', $this->pdf('M'))])->assertOk()->json();
        $this->assertTrue($r['ok']); $this->assertSame('Maderas del Sur', $r['proveedor']); $this->assertNotEmpty($r['borrador_id']);
        $e = FacturaEntrante::firstOrFail();
        $this->assertSame('subida', $e->origen); $this->assertSame($this->dueno->email, $e->remitente); $this->assertStringContainsString('todavía no está cargado', $e->detalle);
        $this->assertNull(CompraBorrador::first()->contact_id);
        $lista = $this->getJson('/proveedores/compras/bandeja')->json();
        $this->assertNull($lista['direccion'], 'Sin casilla configurada no hay dirección, pero subir anda igual'); $this->assertSame(1, $lista['por_revisar']);

        // Bigcito la cuenta.
        $t = app(Catalogo::class)->llamar($this->dueno, 'facturas_por_revisar', [], 'asistente');
        $this->assertCount(1, $t['para_revisar']); $this->assertSame('Maderas del Sur', $t['para_revisar'][0]['proveedor']); $this->assertStringContainsString('1 factura', $t['resumen']);

        // Quitarla borra también la «a medio cargar» y apaga el aviso.
        $this->deleteJson("/proveedores/compras/bandeja/{$e->id}")->assertOk();
        $this->assertSame('descartada', $e->fresh()->estado); $this->assertSame(0, CompraBorrador::count());
        $this->assertNotNull(Alerta::where('tipo', 'factura_casilla')->first()->resuelta_en);
        $this->assertSame([], $this->getJson('/proveedores/compras/bandeja')->json('lista'));
        // Descartar desde «a medio cargar» también la saca de la casilla.
        $this->post('/proveedores/compras/bandeja', ['archivo' => UploadedFile::fake()->createWithContent('otra.pdf', $this->pdf('N'))])->assertOk();
        $bor = CompraBorrador::firstOrFail();
        $this->deleteJson("/proveedores/compras/borradores/{$bor->id}")->assertOk();
        $this->assertSame(0, FacturaEntrante::where('estado', 'lista')->count());

        // Sin clave de IA: queda guardada como «no se pudo leer», con el archivo para cargarla a mano.
        $this->empresa->forceFill(['ia_settings' => null])->save(); $this->dueno->unsetRelation('business');
        $r = $this->post('/proveedores/compras/bandeja', ['archivo' => UploadedFile::fake()->createWithContent('sin-ia.pdf', $this->pdf('S'))])->assertOk()->json();
        $this->assertFalse($r['ok']); $this->assertSame('sin_leer', $r['estado']); $this->assertSame('No se pudo leer', $r['estado_txt']);
        $this->get('/proveedores/compras/bandeja/' . FacturaEntrante::where('estado', 'sin_leer')->value('id') . '/archivo')->assertOk();
        // Sólo PDF y fotos.
        $this->postJson('/proveedores/compras/bandeja', ['archivo' => UploadedFile::fake()->create('lista.xlsx', 10)])->assertStatus(422);
    }

    public function test_el_superadmin_carga_la_casilla_y_la_clave_no_se_muestra(): void
    {
        $su = \App\Models\User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? \App\Models\User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); Auth::login($su);
        $this->post('/admin/sistema', ['dias_prueba' => 15, 'dias_gracia' => 7, 'aviso_dias' => '7,3,1', 'casilla_host' => 'imap.hostinger.com', 'casilla_puerto' => 993, 'casilla_usuario' => 'facturas@bigsysweb.com', 'casilla_clave' => 'secreta'])->assertRedirect();
        $c = $this->get('/admin/sistema')->viewData('page')['props']['config'];
        $this->assertSame('imap.hostinger.com', $c['casilla_host']); $this->assertTrue($c['casilla_clave_set']); $this->assertArrayNotHasKey('casilla_clave', $c);
        $this->assertTrue(BandejaFacturasService::configurada());
        $this->assertStringEndsWith('@bigsysweb.com', BandejaFacturasService::direccion($this->empresa));
        // Guardar de nuevo sin clave no la borra; la dirección pública puede ser otra.
        $this->post('/admin/sistema', ['dias_prueba' => 15, 'dias_gracia' => 7, 'aviso_dias' => '7,3,1', 'casilla_direccion' => 'compras@bigsysweb.com'])->assertRedirect();
        $this->assertTrue(BandejaFacturasService::configurada());
        $this->assertStringStartsWith('compras+', BandejaFacturasService::direccion($this->empresa));
    }
}

// Una casilla IMAP de mentira: contesta lo justo para vaciar una bandeja.
class CasillaFalsa
{
    public array $enviado = [];
    public array $vistos = [];
    private array $cola = [];

    public function __construct(private array $mensajes, private bool $aceptaClave = true) {}

    public function escribir(string $t): void
    {
        $t = rtrim($t, "\r\n"); $this->enviado[] = $t;
        [$tag, $cmd] = explode(' ', $t, 2);
        if (str_starts_with($cmd, 'LOGIN')) { $this->cola[] = $this->aceptaClave ? "{$tag} OK LOGIN completed\r\n" : "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n"; }
        elseif (str_starts_with($cmd, 'SELECT')) { $this->cola[] = '* ' . count($this->mensajes) . " EXISTS\r\n"; $this->cola[] = "* OK [UIDVALIDITY 1] ok\r\n"; $this->cola[] = "{$tag} OK [READ-WRITE] SELECT completed\r\n"; }
        elseif ($cmd === 'UID SEARCH UNSEEN') { $this->cola[] = rtrim('* SEARCH ' . implode(' ', array_diff(array_keys($this->mensajes), $this->vistos))) . "\r\n"; $this->cola[] = "{$tag} OK SEARCH completed\r\n"; }
        elseif (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\]\)$/', $cmd, $m)) { $c = $this->mensajes[(int) $m[1]]; $this->cola[] = "* 1 FETCH (UID {$m[1]} BODY[] {" . strlen($c) . "}\r\n"; $this->cola[] = ['literal' => $c]; $this->cola[] = ")\r\n"; $this->cola[] = "{$tag} OK FETCH completed\r\n"; }
        elseif (preg_match('/^UID STORE (\d+) \+FLAGS\.SILENT \(\\\\Seen\)$/', $cmd, $m)) { $this->vistos[] = (int) $m[1]; $this->cola[] = "{$tag} OK STORE completed\r\n"; }
        elseif ($cmd === 'LOGOUT') { $this->cola[] = "* BYE\r\n"; $this->cola[] = "{$tag} OK LOGOUT completed\r\n"; }
        else { $this->cola[] = "{$tag} BAD comando desconocido\r\n"; }
    }

    public function linea(): string|false { $x = array_shift($this->cola); return is_string($x) ? $x : false; }

    public function bytes(int $n): string { $x = array_shift($this->cola); return substr($x['literal'], 0, $n); }
}
