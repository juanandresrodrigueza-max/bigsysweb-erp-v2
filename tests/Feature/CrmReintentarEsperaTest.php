<?php

namespace Tests\Feature;

use App\Services\Integraciones\CrmSaludService;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

// Hallazgo 5.3 bis: Reintentar también adelanta los envíos que esperan su próximo intento (backoff), no sólo los que ya fallaron del todo.
class CrmReintentarEsperaTest extends ErpTestCase
{
    public function test_reintentar_adelanta_lo_que_espera_con_backoff(): void
    {
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'k', 'webhook_secreto' => 'wh', 'lista_precios' => 1]]);
        // El payload tal como lo guarda la cola database (comillas escapadas dentro de command).
        $payload = json_encode(['displayName' => 'App\\Jobs\\NotificarCrmJob', 'data' => ['commandName' => 'App\\Jobs\\NotificarCrmJob', 'command' => serialize(new \App\Jobs\NotificarCrmJob($this->empresa->id, 'comprobante.emitido', ['id' => 1]))]]);
        $this->assertStringContainsString('\\"businessId\\";i:', $payload);
        $futuro = now()->addMinutes(20)->getTimestamp();
        DB::table('jobs')->insert([['queue' => 'default', 'payload' => $payload, 'attempts' => 2, 'available_at' => $futuro, 'created_at' => now()->getTimestamp()], ['queue' => 'default', 'payload' => str_replace('NotificarCrmJob', 'OtroJob', $payload), 'attempts' => 0, 'available_at' => $futuro, 'created_at' => now()->getTimestamp()]]);
        $this->assertSame(1, app(CrmSaludService::class)->cola($this->empresa->fresh())['pendientes'], 'La cola encuentra el envío con el payload real');
        $this->assertSame(1, app(CrmSaludService::class)->reintentar($this->empresa->fresh()));
        $this->assertLessThanOrEqual(now()->getTimestamp(), (int) DB::table('jobs')->where('payload', 'like', '%NotificarCrmJob%')->value('available_at'), 'El del CRM sale ya');
        $this->assertSame($futuro, (int) DB::table('jobs')->where('payload', 'like', '%OtroJob%')->value('available_at'), 'Los demás jobs no se tocan');
        $this->assertSame(0, app(CrmSaludService::class)->reintentar($this->empresa->fresh()), 'Nada más que adelantar');
    }
}
