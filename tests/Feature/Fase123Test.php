<?php

namespace Tests\Feature;

use App\Models\CopiaCompleta;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Migracion\CopiaCompletaService;
use App\Services\Monitoreo\SaludService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 123: la copia completa de la base de datos, cifrada con la clave del dueño y fuera del servidor.
class Fase123Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.backups.disk' => 'backblaze', 'services.backups.prefijo' => 'bigsysweb/erp/']);
        Storage::fake('backblaze');
    }

    protected function tearDown(): void
    {
        $dir = Storage::disk('local')->path(CopiaCompletaService::DIR);
        if (is_dir($dir)) { array_map('unlink', glob("{$dir}/*")); @rmdir($dir); }
        parent::tearDown();
    }

    private function superadmin(): void
    {
        $su = User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); Auth::login($su);
    }

    private function salud(): array { return collect(app(SaludService::class)->verificar()['controles'])->firstWhere('key', 'copia_completa'); }

    public function test_sin_clave_no_se_hace_y_la_salud_lo_avisa(): void
    {
        $this->artisan('backups:completa')->assertFailed();
        $this->assertSame(0, CopiaCompleta::count());
        $s = $this->salud(); $this->assertSame('aviso', $s['estado']); $this->assertStringContainsString('Falta la clave', $s['detalle']);
    }

    public function test_la_clave_se_muestra_una_vez_y_se_guarda_cifrada(): void
    {
        $this->superadmin();
        $this->post('/admin/sistema/copia/clave')->assertSessionHas('prueba', fn($p) => $p['que'] === 'copia_clave' && preg_match('/^([A-HJ-NP-Z2-9]{5}-){5}[A-HJ-NP-Z2-9]{5}$/', $p['texto']));
        $k = CopiaCompletaService::clave(); $this->assertNotNull($k);
        $crudo = json_encode(DB::table('sistema_config')->where('clave', 'copia_clave')->value('valor'));
        $this->assertStringNotContainsString($k, $crudo);
        // La pantalla dice que hay clave, pero nunca la muestra de nuevo.
        $p = $this->get('/admin/sistema')->viewData('page')['props']['copia'];
        $this->assertTrue($p['clave']); $this->assertStringNotContainsString($k, json_encode($p));
    }

    public function test_la_copia_se_hace_cifrada_se_comprueba_y_sube_afuera_y_se_restaura_con_la_clave(): void
    {
        $k = CopiaCompletaService::generarClave();
        $this->artisan('backups:completa')->assertSuccessful();
        $c = CopiaCompleta::firstOrFail();
        $this->assertSame('ok', $c->estado); $this->assertTrue($c->verificada); $this->assertGreaterThan(20, $c->tablas);
        $this->assertStringStartsWith('backblaze:bigsysweb/erp/completa/' . now()->format('Y/m') . '/erp-', $c->remoto);
        [, $ruta] = explode(':', $c->remoto, 2);
        Storage::disk('backblaze')->assertExists($ruta);
        // En el servidor no queda nada sin cifrar.
        $this->assertSame([], glob(Storage::disk('local')->path(CopiaCompletaService::DIR) . '/*.dump'));
        // Lo que está afuera se abre con openssl y la clave, como dice docs/RESTAURAR-ERP.md…
        $tmp = tempnam(sys_get_temp_dir(), 'cc'); file_put_contents("{$tmp}.enc", Storage::disk('backblaze')->get($ruta));
        $ok = Process::env(['K' => $k])->run(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-in', "{$tmp}.enc", '-out', "{$tmp}.dump", '-pass', 'env:K']);
        $this->assertTrue($ok->successful(), $ok->errorOutput());
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertGreaterThan(20, (int) (new \PDO("sqlite:{$tmp}.dump"))->query("select count(*) from sqlite_master where type = 'table'")->fetchColumn());
            $this->assertSame(1, (int) (new \PDO("sqlite:{$tmp}.dump"))->query("select count(*) from businesses where slug = 'test'")->fetchColumn());
        } else {
            $this->assertStringContainsString('TABLE DATA public businesses', Process::run(['pg_restore', '--list', "{$tmp}.dump"])->output());
        }
        // …y con otra clave, no.
        $mal = Process::env(['K' => 'OTRA-CLAVE'])->run(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-in', "{$tmp}.enc", '-out', "{$tmp}.mal", '-pass', 'env:K']);
        $this->assertFalse($mal->successful());
        foreach (['', '.enc', '.dump', '.mal'] as $x) @unlink($tmp . $x);
        // La salud lo da por bueno.
        $s = $this->salud(); $this->assertSame('ok', $s['estado'], $s['detalle']); $this->assertStringContainsString('cifrada y en backblaze', $s['detalle']);
    }

    public function test_hacer_una_ahora_desde_el_panel(): void
    {
        CopiaCompletaService::generarClave(); $this->superadmin();
        $this->post('/admin/sistema/copia/ahora')->assertSessionHas('prueba', fn($p) => $p['que'] === 'copia' && $p['ok'] && str_contains($p['texto'], 'subida fuera del servidor'));
        $this->assertSame('manual', CopiaCompleta::firstOrFail()->origen);
        $l = $this->get('/admin/sistema')->viewData('page')['props']['copia']['lista'];
        $this->assertSame('ok', $l[0]['estado']); $this->assertTrue($l[0]['remoto']);
    }

    public function test_si_falla_queda_anotado_y_la_salud_lo_marca_critico(): void
    {
        CopiaCompletaService::generarClave();
        Process::fake(['*pg_dump*' => Process::result(errorOutput: 'pg_dump roto', exitCode: 1), '*openssl*' => Process::result(errorOutput: 'openssl roto', exitCode: 1), '*' => Process::result()]);
        $this->artisan('backups:completa')->assertFailed();
        $c = CopiaCompleta::firstOrFail(); $this->assertSame('error', $c->estado); $this->assertStringContainsString('roto', $c->error);
        $this->assertSame([], glob(Storage::disk('local')->path(CopiaCompletaService::DIR) . '/*.dump'));
        $this->assertSame('critico', $this->salud()['estado']);
    }

    public function test_afuera_quedan_14_dias_y_la_primera_de_cada_mes_por_un_anio(): void
    {
        $hacer = function (string $fecha) {
            $c = CopiaCompleta::create(['estado' => 'ok', 'remoto' => "backblaze:bigsysweb/erp/completa/{$fecha}.dump.enc", 'remoto_env' => "backblaze:bigsysweb/erp/completa/{$fecha}.env.enc", 'bytes' => 10, 'tablas' => 5]);
            $c->forceFill(['created_at' => \Carbon\Carbon::parse($fecha)])->save();
            Storage::disk('backblaze')->put("bigsysweb/erp/completa/{$fecha}.dump.enc", 'x'); Storage::disk('backblaze')->put("bigsysweb/erp/completa/{$fecha}.env.enc", 'x');
            return $c;
        };
        $this->travelTo(\Carbon\Carbon::parse('2026-11-20 04:00'));
        $reciente = $hacer('2026-11-15 03:20');
        $vieja = $hacer('2026-10-20 03:20');           // ni de los 14 días ni la primera del mes
        $primeraOct = $hacer('2026-10-01 03:20');      // primera de octubre: queda
        $muyVieja = $hacer('2025-09-01 03:20');        // primera de su mes, pero de hace más de un año
        app(CopiaCompletaService::class)->podar();
        $this->assertFalse($reciente->fresh()->podada); $this->assertFalse($primeraOct->fresh()->podada);
        $this->assertTrue($vieja->fresh()->podada); $this->assertTrue($muyVieja->fresh()->podada);
        Storage::disk('backblaze')->assertMissing('bigsysweb/erp/completa/2026-10-20 03:20.dump.enc');
        Storage::disk('backblaze')->assertMissing('bigsysweb/erp/completa/2025-09-01 03:20.env.enc');
        Storage::disk('backblaze')->assertExists('bigsysweb/erp/completa/2026-10-01 03:20.dump.enc');
    }

    public function test_corre_cada_noche(): void
    {
        $e = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($e) => str_contains($e->command ?? '', 'backups:completa'));
        $this->assertNotNull($e); $this->assertSame('20 3 * * *', $e->expression);
    }
}
