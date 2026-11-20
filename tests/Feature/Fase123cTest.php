<?php

namespace Tests\Feature;

use App\Mail\AvisoAprobacion;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Afip\ArcaPlataforma;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 123.3: aviso a los administradores de BigSys antes de que venza el certificado de ARCA (30, 7 y 1 día, y vencido).
class Fase123cTest extends ErpTestCase
{
    private function certificado(string $vence): string
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'AR', 'organizationName' => 'BIGSYS S.R.L.', 'commonName' => 'bigsyserp', 'serialNumber' => 'CUIT 30714385735'], $k, ['digest_alg' => 'sha256']);
        $dias = (int) floor((strtotime($vence . ' 23:59:00') - time()) / 86400); // openssl firma con el reloj real, no con el de la prueba
        openssl_x509_export(openssl_csr_sign($csr, null, $k, $dias, ['digest_alg' => 'sha256']), $cert); openssl_pkey_export($k, $key);
        ArcaPlataforma::cargar($cert, $key, true);
        return $cert;
    }

    public function test_avisa_a_la_semana_una_sola_vez_y_despues_el_dia_anterior_y_vencido(): void
    {
        Mail::fake();
        User::factory()->create(['is_superadmin' => true, 'status' => 'active', 'email' => 'admin1@bigsys.com.ar', 'business_id' => $this->empresa->id]);
        $this->travelTo(\Carbon\Carbon::parse('2028-11-01 09:12'));
        $this->certificado('2028-11-19');
        // Faltan 18 días: ya está dentro de los 30, avisa una vez.
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Aviso enviado (30)')->assertSuccessful();
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Ya se avisó')->assertSuccessful();
        // Una semana antes.
        $this->travelTo(\Carbon\Carbon::parse('2028-11-12 09:12'));
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Aviso enviado (7)')->assertSuccessful();
        Mail::assertSent(AvisoAprobacion::class, fn($m) => str_contains($m->asunto, 'vence en 7 días') && str_contains($m->texto, 'Cómo renovarlo') && $m->hasTo('admin1@bigsys.com.ar') && str_ends_with((string) $m->url, '/admin/sistema'));
        $this->travelTo(\Carbon\Carbon::parse('2028-11-13 09:12'));
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Ya se avisó')->assertSuccessful();
        // El día anterior y vencido.
        $this->travelTo(\Carbon\Carbon::parse('2028-11-18 09:12'));
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Aviso enviado (1)')->assertSuccessful();
        $this->travelTo(\Carbon\Carbon::parse('2028-11-21 09:12'));
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Aviso enviado (vencido)')->assertSuccessful();
        Mail::assertSent(AvisoAprobacion::class, fn($m) => str_starts_with($m->asunto, 'URGENTE: venció'));
        Mail::assertSent(AvisoAprobacion::class, 4);
    }

    public function test_lejos_del_vencimiento_o_sin_certificado_no_avisa_y_uno_nuevo_reinicia_la_cuenta(): void
    {
        Mail::fake();
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Sin certificado')->assertSuccessful();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-20 09:12'));
        $this->certificado('2028-11-19');
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Faltan')->assertSuccessful();
        // Un certificado nuevo (renovado) vuelve a avisar en sus propios umbrales.
        $this->travelTo(\Carbon\Carbon::parse('2028-11-12 09:12'));
        $this->certificado('2028-11-19');
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Aviso enviado (7)')->assertSuccessful();
        $this->certificado('2028-11-19'); // renovado otra vez (otra huella)
        $this->artisan('arca:vencimiento')->expectsOutputToContain('Aviso enviado (7)')->assertSuccessful();
        Mail::assertSent(AvisoAprobacion::class, 2);
    }

    public function test_corre_todos_los_dias(): void
    {
        $e = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($e) => str_contains($e->command ?? '', 'arca:vencimiento'));
        $this->assertNotNull($e); $this->assertSame('12 9 * * *', $e->expression);
    }
}
