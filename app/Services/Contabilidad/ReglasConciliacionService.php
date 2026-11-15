<?php

namespace App\Services\Contabilidad;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ConciliacionRegla;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\ExtractoItem;
use App\Services\Comprobantes\TransferenciaClienteService;

// Fase 99: reglas que actúan solas al importar un extracto o traer Mercado Pago: el gasto se registra en su categoría, lo que no es del sistema se ignora,
// la transferencia del cliente de siempre se vuelve recibo. Se crean con un clic ("recordar") la primera vez que lo hacés a mano.
class ReglasConciliacionService
{
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $s)));
    }

    // El patrón que se guarda a partir de una descripción: sin números ni fechas (cambian cada vez), las palabras que identifican.
    public static function patronDe(string $descripcion): string
    {
        $t = preg_replace('/\b\d[\d\/\-.:]*\b/', ' ', self::norm($descripcion));
        $palabras = array_values(array_filter(explode(' ', trim(preg_replace('/\s+/', ' ', $t))), fn($p) => mb_strlen($p) > 1));
        return mb_substr(implode(' ', array_slice($palabras, 0, 5)), 0, 160);
    }

    public function coincide(ConciliacionRegla $r, ExtractoItem $it): bool
    {
        if ($r->cuenta_fondos_id && $r->cuenta_fondos_id !== $it->cuenta_fondos_id) return false;
        $m = (float) $it->monto;
        if ($r->sentido === 'ingreso' && $m < 0) return false;
        if ($r->sentido === 'egreso' && $m > 0) return false;
        if ($r->accion === 'recibo' && $m <= 0) return false;
        return $r->patron !== '' && str_contains(self::norm($it->descripcion . ' ' . $it->referencia), self::norm($r->patron));
    }

    // Aplica las reglas a los renglones pendientes de la cuenta. Devuelve cuántos resolvió.
    public function aplicar(CuentaFondos $cuenta): int
    {
        $reglas = ConciliacionRegla::withoutGlobalScopes()->where('business_id', $cuenta->business_id)->where(fn($q) => $q->whereNull('cuenta_fondos_id')->orWhere('cuenta_fondos_id', $cuenta->id))->orderByDesc('veces')->get();
        if ($reglas->isEmpty()) return 0;
        $n = 0;
        foreach (ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'pendiente')->orderBy('fecha')->get() as $it) {
            $r = $reglas->first(fn($r) => $this->coincide($r, $it)); if (! $r) continue;
            try { $this->ejecutar($r, $it); $r->increment('veces'); $r->forceFill(['ultima_vez' => now()])->save(); $n++; } catch (\Throwable) {}
        }
        return $n;
    }

    public function ejecutar(ConciliacionRegla $r, ExtractoItem $it): void
    {
        match ($r->accion) {
            'ignorar' => $it->update(['estado' => 'ignorado', 'match' => 'regla']),
            'gasto' => tap(app(ConciliacionService::class)->registrarDesdeExtracto($it, $r->expense_category_id, $r->concepto ?: null), fn() => $it->update(['match' => 'regla'])),
            'recibo' => tap(app(TransferenciaClienteService::class)->reciboDesdeExtracto($it, Contact::withoutGlobalScopes()->findOrFail($r->contact_id)), fn() => $it->update(['match' => 'regla'])),
            default => null,
        };
    }

    public function crear(Business $b, array $d, ?int $userId = null): ConciliacionRegla
    {
        $patron = self::norm($d['patron'] ?? ''); abort_if(mb_strlen($patron) < 3, 422, 'El patrón tiene que tener al menos 3 letras.');
        $r = ConciliacionRegla::create(['business_id' => $b->id, 'cuenta_fondos_id' => $d['cuenta_fondos_id'] ?? null, 'patron' => $patron, 'sentido' => $d['sentido'] ?? 'ambos', 'accion' => $d['accion'], 'expense_category_id' => $d['accion'] === 'gasto' ? ($d['expense_category_id'] ?? null) : null, 'contact_id' => $d['accion'] === 'recibo' ? ($d['contact_id'] ?? null) : null, 'concepto' => $d['concepto'] ?? null, 'creada_por' => $userId]);
        AuditLog::registrar('crear', $r, "Regla de conciliación: «{$r->patron}» → " . ConciliacionRegla::ACCIONES[$r->accion]);
        return $r;
    }

    // "Recordar": la regla que sale de lo que el usuario acaba de hacer con un renglón.
    public function recordarDesde(ExtractoItem $it, string $accion, array $datos = [], ?int $userId = null): ?ConciliacionRegla
    {
        $patron = self::patronDe($it->descripcion); if (mb_strlen($patron) < 3) return null;
        $b = Business::withoutGlobalScopes()->findOrFail($it->business_id);
        $ya = ConciliacionRegla::withoutGlobalScopes()->where('business_id', $b->id)->where('patron', $patron)->where('accion', $accion)->first(); if ($ya) return $ya;
        return $this->crear($b, ['patron' => $patron, 'sentido' => (float) $it->monto < 0 ? 'egreso' : 'ingreso', 'accion' => $accion] + $datos, $userId);
    }

    public function listado(Business $b): array
    {
        return ConciliacionRegla::withoutGlobalScopes()->with('categoria:id,name', 'cliente:id,name', 'cuenta:id,nombre')->where('business_id', $b->id)->orderByDesc('veces')->orderBy('patron')->get()
            ->map(fn($r) => ['id' => $r->id, 'patron' => $r->patron, 'sentido' => $r->sentido, 'accion' => $r->accion, 'accion_label' => ConciliacionRegla::ACCIONES[$r->accion] ?? $r->accion, 'categoria' => $r->categoria?->name, 'cliente' => $r->cliente?->name, 'cuenta' => $r->cuenta?->nombre, 'concepto' => $r->concepto, 'veces' => $r->veces, 'ultima_vez' => $r->ultima_vez?->format('d/m/Y')])->all();
    }
}
