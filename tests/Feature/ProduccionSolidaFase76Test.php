<?php

namespace Tests\Feature;

use App\Mail\ErrorNuevo;
use App\Mail\ResumenSemanalSistema;
use App\Mcp\Catalogo;
use App\Models\ErrorSistema;
use App\Models\SistemaConfig;
use App\Models\Ticket;
use App\Services\Ventas\EntregasService;
use App\Services\Ventas\RepartoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\ErpTestCase;

// Fase 76: lo que rompe en producción se ve y se arregla.
class ProduccionSolidaFase76Test extends ErpTestCase
{
    public function test_el_remito_de_una_entrega_no_vuelve_a_la_lista_de_entregas_pendientes(): void
    {
        $cli = $this->cliente(['name' => 'Ferretería Sur']); $cem = $this->articulo(['name' => 'Cemento', 'stock_inicial' => 100]);
        $f = $this->factura($cli, [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 10, 'precio_unit' => 8000, 'alicuota_iva' => 21]], ['entrega_pendiente' => true]);
        // Se entregan 6: nace el remito desde la factura.
        $rem = app(EntregasService::class)->convertirParcial($f, 'REM', [$f->items->first()->id => 6]);
        $this->assertSame('REM', $rem->tipo); $this->assertSame($f->id, $rem->origen_id);
        $pend = collect(app(RepartoService::class)->pendientes())->keyBy('cliente');
        $docs = $pend['Ferretería Sur']['documentos'];
        $this->assertCount(1, $docs, 'Sólo la factura: el remito de la entrega ya se entregó'); $this->assertSame($f->id, $docs[0]['id']); $this->assertEquals(4, $docs[0]['items'][0]['pendiente']);
        // Un remito suelto (sin factura detrás) sí es una entrega pendiente.
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $suelto = $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'REM', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => [['product_id' => $cem->id, 'cantidad' => 3, 'precio_unit' => 8000]]]));
        $docs = collect(app(RepartoService::class)->pendientes())->keyBy('cliente')['Ferretería Sur']['documentos'];
        $this->assertSame([$f->id, $suelto->id], array_column($docs, 'id'), 'Orden estable: por fecha y después por id');
    }

    public function test_un_error_nuevo_avisa_al_instante_y_uno_que_vuelve_tambien_pero_sin_repetirse(): void
    {
        Mail::fake(); SistemaConfig::set('monitoreo_email', 'admin@bigsys.test');
        Route::get('/_test/explota', fn() => throw new \RuntimeException('Se rompió algo'))->middleware('web');
        $this->get('/_test/explota')->assertStatus(500);
        $this->get('/_test/explota')->assertStatus(500);
        $this->get('/_test/explota')->assertStatus(500);
        Mail::assertSent(ErrorNuevo::class, 1); // la primera vez, no las tres
        Mail::assertSent(ErrorNuevo::class, fn($m) => ! $m->volvio && $m->error->clase === 'RuntimeException' && $m->hasTo('admin@bigsys.test'));
        // Se marca resuelto y vuelve a pasar: avisa que volvió.
        ErrorSistema::first()->update(['resuelto_en' => now()]);
        $this->get('/_test/explota')->assertStatus(500);
        Mail::assertSent(ErrorNuevo::class, 2); Mail::assertSent(ErrorNuevo::class, fn($m) => $m->volvio);
        $this->assertNull(ErrorSistema::first()->resuelto_en); $this->assertSame(4, ErrorSistema::first()->veces);
    }

    public function test_la_pantalla_de_error_habla_claro_y_avisar_a_soporte_abre_el_ticket_con_el_error_pegado(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/explota2', fn() => throw new \RuntimeException('Falló la cosa'))->middleware('web');
        $r = $this->get('/_test/explota2')->assertStatus(500);
        $r->assertSee('Algo falló de nuestro lado')->assertSee('Avisar a soporte')->assertDontSee('Falló la cosa'); // nada técnico al usuario
        $err = ErrorSistema::first(); $this->assertNotNull($err); $r->assertSee(substr($err->hash, 0, 8));

        $this->post('/soporte/error', ['hash' => $err->hash, 'url' => 'http://erp.test/_test/explota2', 'mensaje' => 'Estaba facturando'])->assertRedirect();
        $t = Ticket::latest('id')->first();
        $this->assertSame('error', $t->categoria); $this->assertSame('alta', $t->prioridad); $this->assertStringContainsString('RuntimeException', $t->asunto); $this->assertStringContainsString(substr($err->hash, 0, 8), $t->asunto);
        $texto = $t->mensajes[0]['texto'];
        $this->assertStringContainsString("Estaba facturando\n\nReportado", $texto); $this->assertStringContainsString('Falló la cosa', $texto); $this->assertStringContainsString('_test/explota2', $texto); $this->assertStringContainsString(substr($err->hash, 0, 8), $texto);
        // Sin hash (error que no quedó registrado) también se puede reportar.
        $this->post('/soporte/error', ['mensaje' => 'Se puso en blanco'])->assertRedirect();
        $this->assertSame(2, Ticket::count());
    }

    public function test_bigcito_le_cuenta_al_superadmin_como_esta_el_sistema_y_a_nadie_mas(): void
    {
        $this->assertStringContainsString('sólo el administrador', app(Catalogo::class)->llamar($this->dueno->fresh(), 'salud_del_sistema', [], 'asistente')['error']);
        $this->dueno->forceFill(['is_superadmin' => true])->save();
        ErrorSistema::create(['hash' => str_repeat('a', 40), 'clase' => 'RuntimeException', 'mensaje' => 'x', 'archivo' => 'app/X.php', 'linea' => 1, 'ruta' => 'GET x', 'primera_vez' => now(), 'ultima_vez' => now(), 'veces' => 7]);
        $r = app(Catalogo::class)->llamar($this->dueno->fresh(), 'salud_del_sistema', [], 'asistente');
        $this->assertArrayNotHasKey('error', $r); $this->assertSame(7, $r['errores_24h']['total']); $this->assertSame('aaaaaaaa', $r['errores_24h']['principales'][0]['codigo']);
        $this->assertArrayHasKey('controles_con_problemas', $r); $this->assertArrayHasKey('uso_7_dias', $r); $this->assertIsArray($r['empresas_sin_copia_48h']);
    }

    public function test_el_resumen_semanal_llega_al_mail_de_monitoreo_con_uso_errores_salud_y_copias(): void
    {
        Mail::fake();
        SistemaConfig::set('monitoreo_email', 'admin@bigsys.test');
        ErrorSistema::create(['hash' => str_repeat('b', 40), 'clase' => 'LogicException', 'mensaje' => 'y', 'archivo' => 'app/Y.php', 'linea' => 2, 'ruta' => 'GET y', 'primera_vez' => now()->subDays(2), 'ultima_vez' => now()->subDays(2), 'veces' => 3]);
        Artisan::call('sistema:resumen-semanal');
        Mail::assertSent(ResumenSemanalSistema::class, function ($m) {
            $this->assertTrue($m->hasTo('admin@bigsys.test'));
            $this->assertSame(3, $m->r['errores']['total']); $this->assertSame('bbbbbbbb', $m->r['errores']['principales'][0]['codigo']);
            $this->assertArrayHasKey('activas', $m->r['uso']); $this->assertArrayHasKey('sin_copia_48h', $m->r['copias']); $this->assertArrayHasKey('abiertos', $m->r['soporte']);
            return true;
        });
    }
}
