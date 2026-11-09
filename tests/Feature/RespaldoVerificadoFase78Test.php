<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Backup;
use App\Models\Contact;
use App\Services\Migracion\BackupService;
use App\Services\Monitoreo\SaludService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 78: las copias de seguridad sirven de verdad: se abren y cuadran, se ensaya restaurarlas y van fuera del servidor.
class RespaldoVerificadoFase78Test extends ErpTestCase
{
    private function control(string $key): array { return collect(app(SaludService::class)->verificar()['controles'])->firstWhere('key', $key); }

    public function test_cada_copia_se_verifica_al_generarse_y_una_rota_se_nota(): void
    {
        $this->articulo(); $this->cliente(['name' => 'Respaldado']);
        $this->assertSame('aviso', $this->control('copias_verificadas')['estado'], 'Sin copias todavía');
        \App\Jobs\BackupEmpresaJob::dispatch($this->empresa, 'auto'); // QUEUE sync: crea, verifica y (si hay disco) sube
        $bk = Backup::first();
        $this->assertNotNull($bk->verificado_en); $this->assertTrue($bk->verificacion['ok']); $this->assertGreaterThan(3, $bk->verificacion['tablas']); $this->assertSame([], $bk->verificacion['problemas']);
        $this->assertSame('ok', $this->control('copias_verificadas')['estado']);

        // Un zip al que le falta una tabla (o que no abre) no pasa.
        $path = Storage::disk('local')->path($bk->archivo); $zip = new \ZipArchive(); $zip->open($path); $zip->deleteName('datos/contacts.json'); $zip->close();
        $v = app(BackupService::class)->verificar($bk->fresh());
        $this->assertFalse($v['ok']); $this->assertStringContainsString('Falta la tabla contacts', $v['problemas'][0]);
        $this->assertSame('critico', $this->control('copias_verificadas')['estado']); $this->assertStringContainsString($this->empresa->name, $this->control('copias_verificadas')['detalle']);
        file_put_contents($path, 'basura');
        $this->assertStringContainsString('no se puede abrir', app(BackupService::class)->verificar($bk->fresh())['problemas'][0]);
        // La pantalla de la empresa lo muestra.
        $props = $this->get('/configuracion/datos')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['backups'][0]['verificada']); $this->assertNotEmpty($props['backups'][0]['problemas']);
    }

    public function test_el_ensayo_de_restauracion_prueba_la_copia_sin_tocar_los_datos(): void
    {
        $art = $this->articulo(['name' => 'Cemento']); $cli = $this->cliente(['name' => 'Respaldado']);
        $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 2, 'precio_unit' => 100]]);
        $bk = app(BackupService::class)->crear($this->empresa, 'manual');
        // Después de la copia, la empresa sigue trabajando.
        $nuevo = $this->cliente(['name' => 'Posterior a la copia']); $cli->update(['name' => 'Renombrado']);
        $antes = Contact::withoutGlobalScopes()->where('business_id', $this->empresa->id)->count();
        $e = app(BackupService::class)->ensayarRestauracion($bk);
        $this->assertTrue($e['ok'], $e['detalle'] ?? ''); $this->assertGreaterThan(3, $e['tablas']);
        $bk->refresh(); $this->assertTrue($bk->ensayo_ok); $this->assertNotNull($bk->ensayo_en);
        // Nada cambió de verdad: el cliente nuevo sigue, el renombre sigue.
        $this->assertSame('Posterior a la copia', Contact::withoutGlobalScopes()->find($nuevo->id)->name); $this->assertSame('Renombrado', $cli->fresh()->name);
        $this->assertSame($antes, Contact::withoutGlobalScopes()->where('business_id', $this->empresa->id)->count(), 'Ni una fila de más ni de menos');

        // El comando lo hace para todas y el panel lo muestra.
        Artisan::call('backups:probar', ['--ensayo' => true]);
        $this->assertStringContainsString('ensayo OK', Artisan::output());
        $this->dueno->forceFill(['is_superadmin' => true])->save();
        $props = $this->actingAs($this->dueno->fresh())->get('/admin/salud')->assertOk()->viewData('page')['props'];
        $fila = collect($props['copias']['empresas'])->firstWhere('business_id', $this->empresa->id);
        $this->assertTrue($fila['verificada']); $this->assertTrue($fila['ensayo']['ok']); $this->assertFalse($fila['remoto']);
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'salud_del_sistema', [], 'asistente');
        $this->assertSame([], $m['copias']['con_problemas']); $this->assertFalse($m['copias']['fuera_del_servidor']);
    }

    public function test_con_disco_externo_configurado_cada_copia_se_sube_y_sin_el_la_salud_avisa(): void
    {
        $this->cliente();
        $this->assertSame('aviso', $this->control('copias_remotas')['estado']); $this->assertStringContainsString('BACKUP_DISK', $this->control('copias_remotas')['detalle']);
        config(['filesystems.disks.remoto_prueba' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/remoto_prueba')], 'services.backups.disk' => 'remoto_prueba']); Storage::fake('remoto_prueba');
        $this->assertTrue(BackupService::remotoConfigurado());
        \App\Jobs\BackupEmpresaJob::dispatch($this->empresa, 'auto');
        $bk = Backup::first();
        if (! $bk->remoto) app(BackupService::class)->subirRemoto($bk); // que explote con el motivo si el job lo tragó
        $this->assertStringStartsWith('remoto_prueba:bigsysweb/' . $this->empresa->slug . '/', $bk->remoto); $this->assertNotNull($bk->remoto_en);
        Storage::disk('remoto_prueba')->assertExists(substr($bk->remoto, strlen('remoto_prueba:')));
        $this->assertSame('ok', $this->control('copias_remotas')['estado']);
        // Una copia que quedó sin subir (p. ej. el disco estaba caído) pone crítico, y backups:probar la sube.
        $bk->forceFill(['remoto' => null, 'remoto_en' => null])->save();
        $this->assertSame('critico', $this->control('copias_remotas')['estado']);
        Artisan::call('backups:probar');
        $this->assertStringContainsString('subida al disco externo', Artisan::output()); $this->assertNotNull($bk->fresh()->remoto);
        $this->assertSame('ok', $this->control('copias_remotas')['estado']);
    }
}
