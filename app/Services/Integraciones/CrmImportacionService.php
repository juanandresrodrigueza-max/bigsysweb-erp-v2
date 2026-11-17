<?php

namespace App\Services\Integraciones;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Models\User;
use App\Support\Cuit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Fase 116 (parte 2) · Carga inicial CRM → ERP: lo que la empresa ya tenía en el CRM antes de conectar. Lotes de hasta 500, idempotentes:
// contactos (por id del CRM → CUIT → mail), presupuestos aceptados que nunca llegaron (por crm_quote_id), comprobantes que el CRM ya
// emitió con CAE (sólo historial: no se re-emiten ni tocan la numeración; quedan «Emitida en el CRM») y saldos iniciales por cliente
// (comprobante interno «Saldo inicial desde el CRM», para que la cuenta corriente arranque con lo que se debía).
class CrmImportacionService
{
    public const LOTE = 500;
    public const TIPOS_HISTORICOS = ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NCE', 'NDA', 'NDB', 'NDC', 'NDE'];

    public function contactos(Business $b, array $items): array
    {
        return $this->lote($items, function (array $k) use ($b) {
            if (empty($k['id'])) return ['ok' => false, 'motivo' => 'Contacto sin id del CRM.'];
            $r = CrmSyncService::recibirContacto($b, $k);
            // Proveedores del CRM: entran como proveedor (o «ambos» si ya era cliente).
            if (($r['ok'] ?? false) && in_array(strtolower((string) ($k['tipo'] ?? $k['type'] ?? '')), ['proveedor', 'supplier'], true) && ($c = Contact::withoutGlobalScopes()->find($r['id'])) && $c->type === 'customer') {
                $c->forceFill(['type' => $r['accion'] === 'creado' ? 'supplier' : 'both'])->save();
            }
            return $r;
        });
    }

    public function presupuestos(Business $b, array $items): array
    {
        return $this->lote($items, fn(array $q) => CrmSyncService::recibirPresupuesto($b, (array) ($q['quote'] ?? $q), is_array($q['contact'] ?? null) ? $q['contact'] : null));
    }

    // Facturas y notas que el CRM ya emitió con CAE antes de conectar. No pasan por ARCA ni por la numeración del ERP: se guardan tal cual,
    // marcadas «Emitida en el CRM». `saldo` es lo que todavía incide en la cuenta corriente (factura: lo pendiente de cobro; nota de crédito:
    // el crédito sin aplicar); 0 o ausente = sólo historial. No mueven stock ni contabilidad (el contador carga los saldos de apertura).
    public function comprobantesHistoricos(Business $b, array $items): array
    {
        $user = $this->usuario($b); $loc = $this->sucursal($b);
        return $this->lote($items, function (array $d) use ($b, $user, $loc) {
            $crmId = (string) ($d['crm_document_id'] ?? $d['id'] ?? '');
            if ($crmId === '') return ['ok' => false, 'motivo' => 'Comprobante sin crm_document_id.'];
            $tipo = strtoupper((string) ($d['tipo'] ?? ''));
            if (! in_array($tipo, self::TIPOS_HISTORICOS, true)) return ['ok' => false, 'motivo' => "Tipo {$tipo} no es factura, nota de crédito ni de débito (FA, FB, FC, NCA, NCB, NCC, NDA, NDB, NDC)."];
            $pv = (int) ($d['punto_venta'] ?? 0); $num = (int) ($d['numero'] ?? 0);
            if ($pv <= 0 || $num <= 0 || empty($d['fecha'])) return ['ok' => false, 'motivo' => 'Faltan punto de venta, número o fecha.'];
            $ya = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_external_id', "crm-doc-{$crmId}")->first()
                ?? Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('tipo', $tipo)->where('punto_venta', $pv)->where('numero', $num)->where('estado', '!=', 'borrador')->first();
            if ($ya) return ['ok' => true, 'accion' => 'existente', 'id' => $ya->id, 'numero' => $ya->numeroFormateado()];
            $cli = CrmSyncService::contactoPara($b, (array) ($d['cliente'] ?? ['nombre' => 'Cliente del CRM']));
            $def = Comprobante::TIPOS[$tipo]; $items = []; $neto = 0; $iva = 0; $orden = 0;
            foreach ((array) ($d['items'] ?? []) as $it) {
                $cant = (float) ($it['cantidad'] ?? 1); $pu = (float) ($it['precio_unitario'] ?? $it['precio_unit'] ?? 0); $al = (float) ($it['alicuota_iva'] ?? 21);
                $prod = null;
                if (! empty($it['external_id']) && ctype_digit((string) $it['external_id'])) $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->find((int) $it['external_id']);
                if (! $prod && ! empty($it['sku'])) $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->where(fn($w) => $w->where('sku', $it['sku'])->orWhere('barcode', $it['sku']))->first();
                $n = round($cant * $pu, 2); $i = round($n * $al / 100, 2); $neto += $n; $iva += $i;
                $items[] = ['product_id' => $prod?->id, 'descripcion' => (string) ($it['descripcion'] ?? $prod?->name ?? 'Ítem'), 'cantidad' => $cant, 'unidad' => $prod?->unit ?? 'un', 'precio_unit' => $pu, 'descuento' => 0, 'alicuota_iva' => $al, 'neto' => $n, 'iva' => $i, 'total' => round($n + $i, 2), 'orden' => ++$orden];
            }
            $total = isset($d['total']) ? round((float) $d['total'], 2) : round($neto + $iva, 2);
            if (! $items) { $items[] = ['product_id' => null, 'descripcion' => 'Comprobante emitido en el CRM', 'cantidad' => 1, 'unidad' => 'un', 'precio_unit' => round($total / 1.21, 2), 'descuento' => 0, 'alicuota_iva' => 21, 'neto' => round($total / 1.21, 2), 'iva' => round($total - $total / 1.21, 2), 'total' => $total, 'orden' => 1]; $neto = $items[0]['neto']; $iva = $items[0]['iva']; }
            $saldo = max(0, min($total, round((float) ($d['saldo'] ?? 0), 2)));
            $origen = ! empty($d['origen_crm_document_id']) ? Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_external_id', 'crm-doc-' . $d['origen_crm_document_id'])->first() : null;
            return DB::transaction(function () use ($b, $user, $loc, $d, $cli, $tipo, $pv, $num, $def, $items, $neto, $iva, $total, $saldo, $origen, $crmId) {
                $c = Comprobante::withoutGlobalScopes()->create([
                    'business_id' => $b->id, 'business_location_id' => $loc, 'contact_id' => $cli->id, 'user_id' => $user?->id, 'origen_id' => $origen?->id, 'direccion' => 'venta', 'tipo' => $tipo, 'punto_venta' => $pv, 'numero' => $num,
                    'fecha' => $d['fecha'], 'fecha_vto' => $d['vencimiento'] ?? $d['fecha'], 'condicion' => 'cta_cte', 'moneda' => 'ARS', 'cotizacion' => 1,
                    'neto' => round($neto, 2), 'exento' => 0, 'iva' => round($iva, 2), 'percepciones' => 0, 'descuento' => 0, 'total' => $total, 'saldo' => $def['cc'] > 0 ? $saldo : 0,
                    'estado' => 'emitido', 'afip_estado' => 'crm', 'cae' => $d['cae'] ?? null, 'cae_vto' => $d['cae_vto'] ?? null, 'emitido_en' => $d['fecha'], 'stock_impactado' => false, 'sin_arca' => false, 'manual' => false,
                    'origen_carga' => 'crm', 'crm_external_id' => "crm-doc-{$crmId}", 'notas' => trim('Emitida en el CRM antes de conectar el ERP (histórico, no se re-emite). ' . ($d['notas'] ?? '')),
                ]);
                foreach ($items as $it) ComprobanteItem::create($it + ['comprobante_id' => $c->id]);
                if ($saldo > 0 && $def['cc'] !== 0) {
                    CuentaCorriente::create(['business_id' => $b->id, 'contact_id' => $cli->id, 'comprobante_id' => $c->id, 'fecha' => $c->fecha, 'fecha_vto' => $def['cc'] > 0 ? $c->fecha_vto : null, 'tipo' => $def['grupo'],
                        'concepto' => "{$c->nombreTipo()} {$c->numeroFormateado()} (CRM)", 'debe' => $def['cc'] > 0 ? $total : 0, 'haber' => $def['cc'] < 0 ? $saldo : 0]);
                    if ($def['cc'] > 0 && $saldo < $total) CuentaCorriente::create(['business_id' => $b->id, 'contact_id' => $cli->id, 'comprobante_id' => $c->id, 'fecha' => $c->fecha, 'tipo' => 'cobro', 'concepto' => "Cobrado en el CRM antes de conectar ({$c->numeroFormateado()})", 'debe' => 0, 'haber' => round($total - $saldo, 2)]);
                    CuentaCorriente::recalcularSaldo($cli->id);
                }
                AuditLog::registrar('crear', $c, "{$c->nombreTipo()} {$c->numeroFormateado()} importada del CRM como historial" . ($saldo > 0 ? ' (saldo $ ' . number_format($saldo, 2, ',', '.') . ')' : ''));
                return ['ok' => true, 'accion' => 'creado', 'id' => $c->id, 'numero' => $c->numeroFormateado(), 'saldo' => (float) $c->saldo];
            });
        });
    }

    // Deuda abierta al conectar: un comprobante interno por cliente («Saldo inicial desde el CRM»). Positivo = nos debe (nota de débito
    // interna); negativo = a favor del cliente (nota de crédito interna). Sin ARCA, sin stock, sin contabilidad; sí cuenta corriente.
    public function saldosIniciales(Business $b, array $items): array
    {
        $user = $this->usuario($b);
        if (! $user) return ['summary' => ['total' => count($items), 'errores' => count($items)], 'results' => [['ok' => false, 'motivo' => 'La empresa no tiene usuarios activos en el ERP.']]];
        $prev = Auth::user(); Auth::setUser($user); CrmSyncService::$silencio = true;
        try {
            return $this->lote($items, function (array $d) use ($b, $user) {
                $saldo = round((float) ($d['saldo'] ?? 0), 2);
                if (abs($saldo) < 0.01) return ['ok' => false, 'motivo' => 'Saldo en cero.'];
                $cli = CrmSyncService::contactoPara($b, (array) ($d['cliente'] ?? ['crm_contact_id' => $d['crm_contact_id'] ?? null, 'cuit' => $d['cuit'] ?? null, 'nombre' => $d['nombre'] ?? 'Cliente del CRM']));
                $clave = 'crm-saldo-inicial-' . ($d['crm_contact_id'] ?? ($d['cliente']['crm_contact_id'] ?? $cli->id));
                if ($ya = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_external_id', $clave)->where('estado', '!=', 'anulado')->first()) return ['ok' => true, 'accion' => 'existente', 'id' => $ya->id, 'numero' => $ya->numeroFormateado(), 'saldo' => $saldo];
                $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
                $c = $svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => $saldo > 0 ? 'NDX' : 'NCX', 'sin_arca' => true, 'fecha' => $d['fecha'] ?? today()->toDateString(), 'dias_vto' => 0, 'condicion' => 'cta_cte',
                    'notas' => trim('Saldo inicial desde el CRM al conectar el ERP. ' . ($d['detalle'] ?? '')),
                    'items' => [['descripcion' => 'Saldo inicial desde el CRM' . (! empty($d['detalle']) ? " · {$d['detalle']}" : ''), 'cantidad' => 1, 'precio_unit' => abs($saldo), 'descuento' => 0, 'alicuota_iva' => 0]]]);
                $c->forceFill(['crm_external_id' => $clave, 'origen_carga' => 'crm'])->save();
                $c = $svc->emitir($c);
                return ['ok' => true, 'accion' => 'creado', 'id' => $c->id, 'numero' => $c->numeroFormateado(), 'tipo' => $c->tipo, 'saldo' => $saldo, 'saldo_cliente' => round((float) $cli->fresh()->balance, 2)];
            });
        } finally { CrmSyncService::$silencio = false; if ($prev) Auth::setUser($prev); else Auth::logout(); }
    }

    private function lote(array $items, callable $fn): array
    {
        $items = array_slice(array_values($items), 0, self::LOTE); $res = []; $s = ['total' => count($items), 'creados' => 0, 'actualizados' => 0, 'existentes' => 0, 'errores' => 0];
        foreach ($items as $i => $it) {
            try { $r = $fn((array) $it); } catch (\Illuminate\Validation\ValidationException $e) { $r = ['ok' => false, 'motivo' => collect($e->errors())->flatten()->implode(' ')]; } catch (\Throwable $e) { $r = ['ok' => false, 'motivo' => mb_substr($e->getMessage(), 0, 200)]; }
            $r['indice'] = $i; $res[] = $r;
            if (! ($r['ok'] ?? false)) $s['errores']++; else $s[match ($r['accion'] ?? '') { 'creado' => 'creados', 'actualizado' => 'actualizados', default => 'existentes' }]++;
        }
        return ['summary' => $s, 'results' => $res];
    }

    private function usuario(Business $b): ?User { return User::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('role_id')->where('status', 'active')->orderBy('id')->first(); }
    private function sucursal(Business $b): ?int { return $b->locations()->where('is_default', true)->value('id') ?? $b->locations()->value('id'); }
}
