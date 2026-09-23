<?php

namespace App\Services\Ventas;

use App\Models\Abono;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Abonos recurrentes: cada vencimiento genera la factura con variables {cuota}, {mes}, {anio} en la descripción.
class AbonosService
{
    public function __construct(private ComprobanteService $comprobantes) {}

    public function guardar(array $d, ?Abono $a = null): Abono
    {
        $user = Auth::user();
        Contact::customers()->findOrFail($d['contact_id']);
        $items = collect($d['items'])->filter(fn($i) => (float) ($i['cantidad'] ?? 0) > 0 && (float) ($i['precio_unit'] ?? 0) >= 0)->values()->all();
        if (! $items) throw ValidationException::withMessages(['items' => 'Cargá al menos un ítem.']);
        $a ??= new Abono(['business_id' => $user->business_id, 'cuota_actual' => 0]);
        $a->fill(['contact_id' => $d['contact_id'], 'descripcion' => $d['descripcion'], 'items' => $items, 'condicion' => $d['condicion'] ?? 'cta_cte', 'frecuencia' => $d['frecuencia'] ?? 'mensual', 'dia_emision' => $d['dia_emision'] ?? 1, 'desde' => $d['desde'], 'hasta' => $d['hasta'] ?? null, 'meses_excluidos' => array_map('intval', $d['meses_excluidos'] ?? []), 'emitir_auto' => $d['emitir_auto'] ?? true, 'activo' => $d['activo'] ?? true, 'notas' => $d['notas'] ?? null]);
        if (! $a->exists || ! $a->proximo) $a->proximo = $a->calcularProximo();
        $a->save();
        AuditLog::registrar($a->wasRecentlyCreated ? 'crear' : 'editar', $a, "Abono {$a->descripcion} de {$a->contact?->name}");
        return $a;
    }

    // Genera la factura del abono (borrador o emitida según emitir_auto) y avanza al próximo vencimiento.
    public function emitir(Abono $a, bool $forzar = false): ?\App\Models\Comprobante
    {
        return DB::transaction(function () use ($a, $forzar) {
            if (! $a->activo || (! $forzar && (! $a->proximo || $a->proximo->gt(today())))) return null;
            $cuota = $a->cuota_actual + 1;
            $fecha = $a->proximo ?? today();
            $vars = ['{cuota}' => $cuota, '{mes}' => ucfirst($fecha->locale('es')->isoFormat('MMMM')), '{anio}' => $fecha->year, '{periodo}' => ucfirst($fecha->locale('es')->isoFormat('MMMM YYYY'))];
            $items = array_map(fn($i) => ['product_id' => $i['product_id'] ?? null, 'descripcion' => strtr($i['descripcion'] ?? $a->descripcion, $vars), 'cantidad' => $i['cantidad'], 'precio_unit' => $i['precio_unit'], 'descuento' => $i['descuento'] ?? 0, 'alicuota_iva' => $i['alicuota_iva'] ?? 21], $a->items);
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $a->contact_id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => $a->condicion, 'notas' => strtr("Abono: {$a->descripcion} · cuota {cuota} · {periodo}", $vars), 'items' => $items]);
            $c->forceFill(['abono_id' => $a->id])->save();
            if ($a->emitir_auto) $c = $this->comprobantes->emitir($c);
            $a->update(['cuota_actual' => $cuota, 'ultimo_emitido_en' => now(), 'proximo' => $a->calcularProximo($fecha)]);
            if (! $a->proximo) $a->update(['activo' => false]);
            AuditLog::registrar('crear', $a, "Abono {$a->descripcion}: cuota {$cuota} → {$c->nombreTipo()} " . ($c->numeroFormateado() ?? 'borrador'));
            return $c;
        });
    }

    // Todos los abonos vencidos de todas las empresas (lo corre el cron).
    public function emitirVencidos(): int
    {
        $n = 0;
        foreach (Abono::withoutGlobalScopes()->where('activo', true)->whereDate('proximo', '<=', today())->get() as $a) {
            $user = \App\Models\User::where('business_id', $a->business_id)->whereNotNull('role_id')->orderBy('id')->first();
            if (! $user) continue;
            $prev = Auth::user(); Auth::setUser($user);
            try { if ($this->emitir($a)) $n++; } catch (\Throwable $e) { \Log::warning("Abono {$a->id}: {$e->getMessage()}"); }
            if ($prev) Auth::setUser($prev); else Auth::logout();
        }
        return $n;
    }
}
