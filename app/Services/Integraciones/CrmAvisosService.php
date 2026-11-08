<?php

namespace App\Services\Integraciones;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CrmEvento;
use Illuminate\Support\Facades\Log;

// Fase 73: avisos cruzados.
//   ERP → CRM: lo crítico de un cliente (cheque rechazado, deuda vieja, pasó el límite, dejó de comprar) le llega al vendedor que lo
//              atiende como tarea del CRM (el CRM la asigna al dueño del contacto). Un aviso por cliente y motivo cada 30 días.
//   CRM → ERP: las tareas vencidas del CRM aparecen en la campana del ERP y se resuelven solas cuando el vendedor las cierra.
class CrmAvisosService
{
    public const DIAS_SIN_REPETIR = 30;
    public const DIAS_DEUDA_VIEJA = 30;

    // Un hecho crítico del ERP sobre un cliente → tarea en el CRM para su vendedor. Devuelve el id de la tarea, o null si no corresponde.
    public static function criticoAlCrm(Business $b, Contact $cli, string $clave, string $titulo, string $texto, string $prioridad = 'high'): ?int
    {
        if (! CrmService::activo($b) || ! $cli->crm_external_id) return null;
        $ref = "cliente:{$cli->id}";
        if (CrmEvento::where('business_id', $b->id)->where('evento', "aviso.{$clave}")->where('referencia', $ref)->where('estado', 'ok')->where('created_at', '>=', now()->subDays(self::DIAS_SIN_REPETIR))->exists()) return null;
        try {
            $id = CrmSyncService::tarea($b, $cli, $titulo, $texto . "\n\nAviso automático del ERP BigSys.", 'call', $prioridad);
            CrmSaludService::registrar($b->id, 'salida', "aviso.{$clave}", 'ok', ['referencia' => $ref, 'detalle' => $titulo . ($id ? " · tarea #{$id}" : '')]);
            return $id;
        } catch (\Throwable $e) {
            CrmSaludService::registrar($b->id, 'salida', "aviso.{$clave}", 'error', ['referencia' => $ref, 'detalle' => mb_substr($e->getMessage(), 0, 200)]);
            Log::warning("CRM aviso {$clave}: " . $e->getMessage());
            return null;
        }
    }

    // La pasada diaria de una empresa: manda lo crítico al CRM y trae las tareas vencidas a la campana.
    public function revisar(Business $b): array
    {
        if (! CrmService::activo($b)) return ['al_crm' => 0, 'del_crm' => 0];
        return ['al_crm' => $this->criticosDelDia($b), 'del_crm' => $this->tareasVencidasALaCampana($b)];
    }

    // Deuda vieja, límite de crédito pasado y clientes que dejaron de comprar, sólo de clientes que están en el CRM.
    public function criticosDelDia(Business $b): int
    {
        $n = 0;
        $enCrm = Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('crm_external_id')->where('is_active', true)->get()->keyBy('id');
        if ($enCrm->isEmpty()) return 0;

        $vieja = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->ventas()->pendientesCobro()->whereIn('contact_id', $enCrm->keys())
            ->where('fecha_vto', '<', today()->subDays(self::DIAS_DEUDA_VIEJA)->toDateString())->selectRaw('contact_id, SUM(saldo) as saldo, COUNT(*) as n, MIN(fecha_vto) as desde')->groupBy('contact_id')->get();
        foreach ($vieja as $r) {
            $c = $enCrm[$r->contact_id]; $dias = \Carbon\Carbon::parse($r->desde)->diffInDays(today());
            $id = self::criticoAlCrm($b, $c, 'deuda_vieja', "Deuda vencida hace {$dias} días: {$c->name}", "{$c->name} tiene $ " . number_format((float) $r->saldo, 2, ',', '.') . " vencidos en {$r->n} comprobante(s); el más viejo venció hace {$dias} días. Hablá con el cliente antes de seguir vendiéndole a cuenta.");
            if ($id) $n++;
        }
        foreach ($enCrm->filter(fn($c) => (float) $c->credit_limit > 0 && (float) $c->balance > (float) $c->credit_limit) as $c) {
            $id = self::criticoAlCrm($b, $c, 'limite_credito', "Pasó su límite de crédito: {$c->name}", "{$c->name} debe $ " . number_format((float) $c->balance, 2, ',', '.') . ' y su límite es $ ' . number_format((float) $c->credit_limit, 2, ',', '.') . '. El ERP no le deja facturar más a cuenta hasta que pague o le amplíen el límite.');
            if ($id) $n++;
        }
        try {
            $h = collect(app(\App\Services\Estadisticas\QueHacerService::class)->hallazgos($b))->firstWhere('clave', 'clientes');
            foreach ((array) ($h['items'] ?? []) as $x) {
                if (! isset($enCrm[$x['id']])) continue;
                $id = self::criticoAlCrm($b, $enCrm[$x['id']], 'dejo_de_comprar', "Dejó de comprar: {$x['nombre']}", "{$x['nombre']} compraba seguido ({$x['facturas']} facturas, $ " . number_format((float) $x['monto'], 0, ',', '.') . " en 6 meses) y no compra desde el " . \Carbon\Carbon::parse($x['ultima'])->format('d/m/Y') . '. Vale un llamado.', 'medium');
                if ($id) $n++;
            }
        } catch (\Throwable $e) { Log::warning('CRM avisos dejó de comprar: ' . $e->getMessage()); }
        return $n;
    }

    // Tareas vencidas del CRM → alertas del ERP (una por tarea; se resuelven cuando dejan de estar vencidas o las cierran).
    public function tareasVencidasALaCampana(Business $b): int
    {
        $t = CrmSyncService::tareasPendientes($b); if ($t === null) return 0;
        $vencidas = collect($t)->where('vencida', true); $n = 0;
        foreach ($vencidas as $x) { $this->alertaTarea($b, $x); $n++; }
        Alerta::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'crm_tarea')->whereNull('resuelta_en')->whereNotIn('modelo_id', $vencidas->pluck('id')->filter()->all() ?: [0])->update(['resuelta_en' => now()]);
        return $n;
    }

    public function alertaTarea(Business $b, array $x): Alerta
    {
        $quien = implode(' · ', array_filter([$x['cliente'] ?? null, $x['vendedor'] ? "vendedor {$x['vendedor']}" : null, $x['vence'] ? 'vencía el ' . \Carbon\Carbon::parse($x['vence'])->format('d/m') : null]));
        return Alerta::emitir(['business_id' => $b->id, 'modulo' => 'clientes', 'tipo' => 'crm_tarea', 'modelo' => 'CrmTarea', 'modelo_id' => (int) ($x['id'] ?? 0), 'severidad' => ($x['prioridad'] ?? '') === 'high' ? 'critica' : 'aviso',
            'titulo' => 'Tarea vencida en el CRM: ' . mb_substr((string) ($x['titulo'] ?? ''), 0, 120), 'detalle' => $quien ?: null, 'url' => $x['url'] ?? ('/integraciones/crm/ir?a=' . urlencode('/tasks?id=' . ($x['id'] ?? '')))]);
    }

    // Webhooks del CRM: task.overdue la pone en la campana al instante; task.completed la saca.
    public static function webhookTarea(Business $b, string $evento, array $t): array
    {
        if (empty($t['id'])) return ['ok' => false, 'error' => 'Falta el id de la tarea.'];
        if ($evento === 'task.completed') {
            $n = Alerta::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'crm_tarea')->where('modelo_id', (int) $t['id'])->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
            return ['ok' => true, 'resueltas' => $n];
        }
        $cli = ! empty($t['contact_id']) ? Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('crm_external_id', (string) $t['contact_id'])->first() : null;
        $a = (new self)->alertaTarea($b, ['id' => $t['id'], 'titulo' => $t['title'] ?? '', 'prioridad' => $t['priority'] ?? null, 'vence' => ! empty($t['due_date']) ? substr((string) $t['due_date'], 0, 10) : null,
            'vendedor' => $t['assigned_to_name'] ?? $t['assigned_to']['name'] ?? null, 'cliente' => $cli?->name ?? $t['contact_name'] ?? null]);
        return ['ok' => true, 'alerta_id' => $a->id];
    }

    public function revisarTodas(): array
    {
        $out = [];
        foreach (Business::where('is_active', true)->get() as $b) {
            if (! CrmService::activo($b)) continue;
            try { $out[$b->id] = $this->revisar($b); } catch (\Throwable $e) { $out[$b->id] = ['error' => $e->getMessage()]; }
        }
        return $out;
    }
}
