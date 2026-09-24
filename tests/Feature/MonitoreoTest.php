<?php

namespace Tests\Feature;

use App\Models\ErrorSistema;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Monitoreo\ErrorReporter;
use App\Services\Monitoreo\SaludService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\ErpTestCase;

// Fase 24.2 · Monitoreo de errores (Sentry o compatible) y salud del servidor.
class MonitoreoTest extends ErpTestCase
{
    private function admin(): User { $a = User::create(['name' => 'BigSys', 'email' => 'admin@bigsys.test', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]); Auth::login($a); $this->actingAs($a); return $a; }

    public function test_los_errores_reales_se_agrupan_y_los_404_y_validaciones_no(): void
    {
        Route::get('/_test/explota', fn() => throw new \RuntimeException('Se rompió algo'))->middleware('web');
        $this->get('/_test/explota')->assertStatus(500);
        $this->get('/_test/explota')->assertStatus(500);
        $this->assertSame(1, ErrorSistema::count(), 'Mismo lugar: una fila');
        $e = ErrorSistema::first();
        $this->assertSame(2, $e->veces);
        $this->assertSame('RuntimeException', $e->clase);
        $this->assertSame('Se rompió algo', $e->mensaje);
        $this->assertSame('GET _test/explota', $e->ruta);
        $this->assertSame($this->empresa->id, $e->business_id);
        $this->assertFalse($e->enviado_sentry, 'Sin DSN no se manda');

        $this->get('/no-existe-esta-pagina')->assertNotFound();
        $cli = $this->cliente(); $this->post("/clientes/{$cli->id}/cobros", [])->assertSessionHasErrors();
        $this->assertSame(1, ErrorSistema::count(), 'Los 404 y las validaciones no son errores del sistema');
        $this->assertTrue(ErrorReporter::ignorable(new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'x')));
        $this->assertFalse(ErrorReporter::ignorable(new \Symfony\Component\HttpKernel\Exception\HttpException(500, 'x')));
    }

    public function test_con_dsn_se_envia_a_sentry_con_el_protocolo_store(): void
    {
        Http::fake(['o123.ingest.sentry.io/*' => Http::response(['id' => 'abc'], 200)]);
        $this->admin();
        $this->post('/admin/salud', ['sentry_dsn' => 'https://clave123@o123.ingest.sentry.io/456', 'monitoreo_email' => 'ops@bigsys.test'])->assertSessionHas('success');
        $this->assertSame('https://o123.ingest.sentry.io/api/456/store/', ErrorReporter::dsn()['url']);
        $this->assertStringContainsString('enc', json_encode(SistemaConfig::where('clave', 'sentry_dsn')->value('valor')), 'El DSN se guarda cifrado');

        app(ErrorReporter::class)->reportar(new \LogicException('Cálculo imposible'));
        Http::assertSent(function ($r) {
            return str_ends_with($r->url(), '/api/456/store/') && str_contains($r->header('X-Sentry-Auth')[0], 'sentry_key=clave123')
                && $r['exception']['values'][0]['type'] === 'LogicException' && $r['exception']['values'][0]['value'] === 'Cálculo imposible' && $r['platform'] === 'php' && ! empty($r['exception']['values'][0]['stacktrace']['frames']);
        });
        $this->assertTrue(ErrorSistema::first()->enviado_sentry);
        $this->post('/admin/salud/probar')->assertSessionHas('success');
        Http::assertSentCount(2);
        $this->post('/admin/salud/borrar-dsn')->assertSessionHas('success');
        $this->assertNull(ErrorReporter::dsn());
    }

    public function test_panel_de_salud_endpoint_publico_y_aviso_por_mail_cuando_hay_criticos(): void
    {
        Mail::fake();
        Cache::put(SaludService::LATIDO_CRON, now()->toIso8601String());
        $r = app(SaludService::class)->verificar();
        $porKey = collect($r['controles'])->keyBy('key');
        $this->assertSame('ok', $porKey['base']['estado']);
        $this->assertSame('ok', $porKey['cron']['estado']);
        $this->assertSame('ok', $porKey['errores']['estado']);
        $this->assertSame('aviso', $porKey['backups']['estado'], 'Sin copias todavía: aviso, no crítico');
        $this->assertNotSame('critico', $r['estado']);

        $this->admin();
        $this->get('/admin/salud')->assertOk()->assertInertia(fn($a) => $a->component('Superadmin/Salud', false)->has('salud.controles', 12)->where('monitoreo.dsn_set', false));
        $this->get('/salud')->assertOk()->assertJsonStructure(['estado', 'hora'])->assertJsonMissing(['controles']);
        SistemaConfig::set('salud_token', 'tok-123'); SistemaConfig::set('monitoreo_email', 'ops@bigsys.test');
        $this->get('/salud?token=tok-123')->assertOk()->assertJsonStructure(['estado', 'controles']);
        $this->get('/salud?token=malo')->assertOk()->assertJsonMissing(['controles']);

        // Cron sin latido hace 2 horas + 25 errores en el día → crítico → 503 y mail (una sola vez por control).
        Cache::put(SaludService::LATIDO_CRON, now()->subHours(2)->toIso8601String());
        for ($i = 0; $i < 25; $i++) ErrorSistema::create(['hash' => "h{$i}", 'clase' => 'X', 'mensaje' => 'm', 'veces' => 1, 'primera_vez' => now(), 'ultima_vez' => now()]);
        $this->get('/salud')->assertStatus(503)->assertJsonPath('estado', 'critico');
        $res = app(SaludService::class)->revisarYAvisar();
        $this->assertSame(2, $res['criticos']); $this->assertSame(2, $res['avisados']);
        Mail::assertSent(\App\Mail\AvisoSalud::class, fn($m) => $m->hasTo('ops@bigsys.test') && count($m->criticos) === 2);
        $res2 = app(SaludService::class)->revisarYAvisar();
        $this->assertSame(0, $res2['avisados'], 'No repite el aviso dentro de las 6 horas');
        $this->artisan('salud:revisar')->assertFailed();

        $e = ErrorSistema::first();
        $this->post("/admin/salud/errores/{$e->id}/resolver")->assertSessionHas('success');
        $this->assertNotNull($e->fresh()->resuelto_en);
    }
}
