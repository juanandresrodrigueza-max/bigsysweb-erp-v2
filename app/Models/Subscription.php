<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Suscripción de una empresa a un plan. Ciclo: prueba → activa → (vencida) gracia → suspendida. Cancelada es baja voluntaria.
class Subscription extends Model
{
    public const ESTADOS = [
        'trial'     => 'En prueba',
        'active'    => 'Activa',
        'grace'     => 'Vencida (gracia)',
        'suspended' => 'Suspendida',
        'cancelled' => 'Cancelada',
    ];

    // Estados con los que la empresa puede seguir usando el sistema.
    public const VIGENTES = ['trial', 'active', 'grace'];

    protected $fillable = [
        'business_id', 'plan_id', 'status', 'billing_cycle', 'amount', 'starts_at', 'ends_at', 'trial_ends_at', 'grace_ends_at',
        'cancelled_at', 'payment_method', 'auto_renew', 'external_id', 'mp_preapproval_id', 'notas',
    ];

    protected $casts = [
        'starts_at'      => 'datetime',
        'ends_at'        => 'datetime',
        'trial_ends_at'  => 'datetime',
        'grace_ends_at'  => 'datetime',
        'cancelled_at'   => 'datetime',
        'auto_renew'     => 'boolean',
        'amount'         => 'decimal:2',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function pagos(): HasMany { return $this->hasMany(PagoSuscripcion::class); }

    public function scopeVigentes(Builder $q): Builder { return $q->whereIn('status', self::VIGENTES); }

    public function isActive(): bool
    {
        return in_array($this->status, self::VIGENTES, true);
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trial';
    }

    // Fecha en la que este estado deja de valer (fin de prueba, vencimiento o fin de gracia).
    public function fechaLimite(): ?\Carbon\Carbon
    {
        return match ($this->status) {
            'trial' => $this->trial_ends_at ?? $this->ends_at,
            'active' => $this->ends_at,
            'grace' => $this->grace_ends_at,
            default => null,
        };
    }

    public function diasRestantes(): ?int
    {
        $f = $this->fechaLimite();
        return $f ? (int) now()->startOfDay()->diffInDays($f->copy()->startOfDay(), false) : null;
    }

    public function estadoLabel(): string
    {
        return self::ESTADOS[$this->status] ?? $this->status;
    }

    // Texto corto para el banner del sistema; null cuando no hay nada que avisar.
    public function aviso(): ?array
    {
        $dias = $this->diasRestantes();
        return match ($this->status) {
            'trial' => ['nivel' => $dias !== null && $dias <= 3 ? 'warn' : 'info', 'texto' => $dias === null ? 'Estás en período de prueba.' : ($dias <= 0 ? 'Tu período de prueba termina hoy.' : "Te quedan {$dias} días de prueba.")],
            'active' => $dias !== null && $dias <= 7 ? ['nivel' => $dias <= 1 ? 'warn' : 'info', 'texto' => $dias <= 0 ? 'Tu suscripción vence hoy.' : "Tu suscripción vence en {$dias} días."] : null,
            'grace' => ['nivel' => 'warn', 'texto' => $dias === null ? 'Tu suscripción está vencida.' : ($dias <= 0 ? 'Tu suscripción está vencida: hoy es el último día antes de la suspensión.' : "Tu suscripción está vencida. Tenés {$dias} días para renovar antes de que se suspenda el acceso.")],
            'suspended' => ['nivel' => 'error', 'texto' => 'Suscripción suspendida por falta de pago.'],
            'cancelled' => ['nivel' => 'error', 'texto' => 'Suscripción cancelada.'],
            default => null,
        };
    }
}
