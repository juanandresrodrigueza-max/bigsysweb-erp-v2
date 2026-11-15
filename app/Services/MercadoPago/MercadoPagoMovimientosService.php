<?php

namespace App\Services\MercadoPago;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\CuentaFondos;
use App\Services\Contabilidad\ConciliacionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

// Fase 99: los movimientos de la cuenta de Mercado Pago entran solos, como si fuera el extracto del banco: cada cobro aprobado (bruto), su comisión
// y las devoluciones. Después corre la misma conciliación automática y las reglas. Se trae con un botón o cada madrugada.
class MercadoPagoMovimientosService
{
    private const API = 'https://api.mercadopago.com';

    public function __construct(private MercadoPagoCobrosService $mp, private ConciliacionService $conc) {}

    public function cuenta(Business $b): ?CuentaFondos
    {
        return CuentaFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('activa', true)->where('tipo', 'billetera')->get()->first(fn($c) => ! empty(($c->conciliacion_config ?? [])['mercadopago']));
    }

    public function configurar(CuentaFondos $cuenta, bool $activo, bool $automatico = true): void
    {
        $cfg = (array) ($cuenta->conciliacion_config ?? []); $cfg['mercadopago'] = $activo; $cfg['mp_automatico'] = $activo && $automatico;
        $cuenta->update(['conciliacion_config' => $cfg, 'concilia' => true]);
    }

    public function conectado(Business $b): bool { return (bool) ($this->mp->config($b)['access_token'] ?? null); }

    // Trae los pagos del período y los guarda como renglones del extracto de la cuenta. Devuelve [nuevos, conciliados, desde, hasta].
    public function traer(Business $b, CuentaFondos $cuenta, ?Carbon $desde = null, ?Carbon $hasta = null): array
    {
        abort_unless($this->conectado($b), 422, 'Mercado Pago no está conectado: Configuración → Mercado Pago → Conectar.');
        $cfg = (array) ($cuenta->conciliacion_config ?? []);
        $desde ??= ! empty($cfg['mp_ultima']) ? Carbon::parse($cfg['mp_ultima'])->subDays(2)->startOfDay() : now()->subDays(30)->startOfDay();
        $hasta ??= now();
        $filas = []; $offset = 0;
        do {
            $r = $this->http($b)->get(self::API . '/v1/payments/search', ['sort' => 'date_approved', 'criteria' => 'asc', 'range' => 'date_approved', 'begin_date' => $desde->toIso8601String(), 'end_date' => $hasta->toIso8601String(), 'limit' => 50, 'offset' => $offset]);
            abort_unless($r->successful(), 422, 'Mercado Pago no respondió (' . $r->status() . '). Probá de nuevo en un rato.');
            $j = $r->json() ?? []; $res = $j['results'] ?? [];
            foreach ($res as $p) foreach ($this->filasDe($p) as $f) $filas[] = $f;
            $offset += 50; $total = (int) (($j['paging']['total'] ?? 0));
        } while (count($res) === 50 && $offset < min($total, 5000));
        if (! $filas) { $this->marcar($cuenta, $hasta); return ['nuevos' => 0, 'conciliados' => 0, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()]; }
        $ext = $this->conc->importarFilas($cuenta, $filas, 'Mercado Pago · ' . $desde->format('d/m') . ' al ' . $hasta->format('d/m'), false);
        // Las comisiones de Mercado Pago son siempre gasto: se registran solas en la categoría de comisiones (o la que sugiera el clasificador).
        if ($ext) {
            $cat = \App\Models\ExpenseCategory::withoutGlobalScopes()->where('business_id', $b->id)->where('name', 'like', '%omisi%')->value('id');
            foreach (\App\Models\ExtractoItem::where('extracto_id', $ext->id)->where('estado', 'pendiente')->where('referencia', 'like', 'fee-%')->get() as $it) { try { $this->conc->registrarDesdeExtracto($it, $cat, 'Comisión Mercado Pago'); $it->update(['match' => 'mp']); } catch (\Throwable) {} }
        }
        $this->marcar($cuenta, $hasta);
        $conciliados = $ext ? \App\Models\ExtractoItem::where('extracto_id', $ext->id)->where('estado', '!=', 'pendiente')->count() : 0;
        if ($ext) AuditLog::registrar('crear', $ext, "Trajo {$ext->items} movimientos de Mercado Pago ({$conciliados} resueltos solos)");
        return ['nuevos' => $ext?->items ?? 0, 'conciliados' => $conciliados, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];
    }

    // Un pago de la API → renglones: el cobro bruto (coincide con el cobro del sistema), la comisión (negativa) y la devolución si la hubo.
    public function filasDe(array $p): array
    {
        $id = (string) ($p['id'] ?? ''); $estado = $p['status'] ?? ''; if (! $id || ! in_array($estado, ['approved', 'refunded', 'charged_back'], true)) return [];
        $fecha = Carbon::parse($p['date_approved'] ?? $p['date_created'] ?? now())->toDateString();
        $bruto = round((float) ($p['transaction_amount'] ?? 0), 2);
        $medio = $p['payment_method_id'] ?? ($p['payment_type_id'] ?? 'mp');
        $quien = $p['payer']['email'] ?? ($p['payer']['first_name'] ?? ''); $desc = trim((string) ($p['description'] ?? '')); $ref = $p['external_reference'] ?? null;
        $out = [];
        if ($bruto > 0) $out[] = ['fecha' => $fecha, 'descripcion' => trim("Cobro Mercado Pago {$medio} · " . ($desc ?: $quien) . " · #{$id}"), 'referencia' => $ref ?: $id, 'monto' => $bruto, 'saldo' => null];
        $fee = round((float) collect($p['fee_details'] ?? [])->sum('amount'), 2);
        if ($fee > 0) $out[] = ['fecha' => $fecha, 'descripcion' => "Comisión Mercado Pago · #{$id}", 'referencia' => "fee-{$id}", 'monto' => -$fee, 'saldo' => null];
        $dev = round((float) ($p['transaction_amount_refunded'] ?? 0), 2);
        if ($dev > 0) $out[] = ['fecha' => Carbon::parse($p['date_last_updated'] ?? $fecha)->toDateString(), 'descripcion' => "Devolución Mercado Pago · #{$id}", 'referencia' => "dev-{$id}", 'monto' => -$dev, 'saldo' => null];
        return $out;
    }

    // Todas las empresas con la cuenta marcada y el token puesto: para el comando de la madrugada.
    public function traerTodas(): array
    {
        $out = [];
        foreach (Business::withoutGlobalScopes()->whereNotNull('mercadopago_settings')->get() as $b) {
            $c = $this->cuenta($b); if (! $c || empty(($c->conciliacion_config ?? [])['mp_automatico']) || ! $this->conectado($b)) continue;
            try { $out[$b->name] = $this->traer($b, $c); } catch (\Throwable $e) { $out[$b->name] = ['error' => $e->getMessage()]; }
        }
        return $out;
    }

    private function marcar(CuentaFondos $cuenta, Carbon $hasta): void { $cfg = (array) ($cuenta->conciliacion_config ?? []); $cfg['mp_ultima'] = $hasta->toDateString(); $cuenta->update(['conciliacion_config' => $cfg]); }
    private function http(Business $b) { return Http::withToken($this->mp->config($b)['access_token'])->timeout(20)->acceptJson(); }
}
