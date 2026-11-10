<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\SistemaConfig;
use App\Services\Fondos\CotizacionService;
use App\Services\Monitoreo\SaludService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// La cotización del dólar no depende de una sola fuente, y la salud cuenta qué pasó en el último intento.
class CotizacionFuentesTest extends ErpTestCase
{
    private function control(string $k): array { return collect(app(SaludService::class)->verificar()['controles'])->firstWhere('key', $k); }

    public function test_si_dolarapi_falla_se_usa_bluelytics_y_la_fecha_nunca_es_futura(): void
    {
        // Un solo fake con respuestas intercambiables (un segundo Http::fake sobre el mismo patrón no pisa al primero).
        $resp = ['dolarapi' => fn() => Http::response('caído', 503), 'bluelytics' => fn() => Http::response(['oficial' => ['value_buy' => 1400, 'value_sell' => 1450], 'blue' => ['value_buy' => 1480, 'value_sell' => 1500], 'last_update' => today()->toDateString() . 'T12:00:00'])];
        Http::fake(['dolarapi.com/*' => function () use (&$resp) { return $resp['dolarapi'](); }, 'api.bluelytics.com.ar/*' => function () use (&$resp) { return $resp['bluelytics'](); }]);
        $r = app(CotizacionService::class)->actualizar();
        $this->assertEqualsWithDelta(1450, $r['oficial'], 0.01); $this->assertEqualsWithDelta(1500, $r['blue'], 0.01);
        $this->assertSame('bluelytics', Cotizacion::withoutGlobalScopes()->whereNull('business_id')->where('tipo', 'oficial')->latest('fecha')->first()->fuente);
        $i = Cache::get(CotizacionService::ULTIMO_INTENTO); $this->assertTrue($i['ok']); $this->assertStringContainsString('bluelytics', $i['detalle']);
        $this->assertSame('ok', $this->control('cotizacion')['estado']); $this->assertStringContainsString('último intento', $this->control('cotizacion')['detalle']);

        // Las dos caídas: queda la última conocida y la salud dice por qué.
        $resp = ['dolarapi' => fn() => Http::response('x', 500), 'bluelytics' => fn() => Http::response('y', 502)];
        $r = app(CotizacionService::class)->actualizar();
        $this->assertStringContainsString('dolarapi: HTTP 500', $r['error']); $this->assertStringContainsString('bluelytics: HTTP 502', $r['error']);
        $this->assertStringContainsString('falló (dolarapi: HTTP 500', $this->control('cotizacion')['detalle']);
        $this->assertSame(1, Artisan::call('cotizaciones:actualizar'));

        // La fila lleva la fecha de HOY aunque la fuente diga otra (el oficial "no cambia desde el 5/11" pero es la vigente hoy), y la salud queda ok.
        $resp['dolarapi'] = fn() => Http::response([['casa' => 'oficial', 'compra' => 1400, 'venta' => 1460, 'fechaActualizacion' => '2099-01-01T00:00:00'], ['casa' => 'blue', 'compra' => 0, 'venta' => 1510, 'fechaActualizacion' => 'rota']]);
        app(CotizacionService::class)->actualizar();
        $this->assertSame(today()->toDateString(), Cotizacion::withoutGlobalScopes()->whereNull('business_id')->where('tipo', 'oficial')->where('fuente', 'dolarapi')->latest('id')->first()->fecha->toDateString());
        $this->assertSame('ok', $this->control('cotizacion')['estado']);
        // Y si la fila fuera vieja pero el intento de hoy anduvo (feriado largo, valor sin cambios), tampoco es aviso.
        Cotizacion::withoutGlobalScopes()->whereNull('business_id')->update(['fecha' => today()->subDays(5)->toDateString()]);
        $this->assertSame('ok', $this->control('cotizacion')['estado']);
        Cache::forget(CotizacionService::ULTIMO_INTENTO);
        $this->assertSame('aviso', $this->control('cotizacion')['estado']);
    }

    public function test_sin_sentry_pero_con_mail_de_monitoreo_el_control_no_es_un_aviso(): void
    {
        SistemaConfig::set('monitoreo_email', 'gerencia@bigsys.com.ar');
        $this->assertSame('ok', $this->control('sentry')['estado']); $this->assertStringContainsString('gerencia@bigsys.com.ar', $this->control('sentry')['detalle']);
    }
}
