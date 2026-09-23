<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'business_id', 'role_id', 'current_location_id', 'ver_consolidado', 'name', 'email', 'password',
        'status', 'language', 'avatar', 'is_superadmin', 'last_login_at', 'tour_visto_en', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_enabled_at',
    ];

    protected $hidden = [
        'two_factor_secret', 'two_factor_recovery_codes','password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'ver_consolidado'   => 'boolean',
            'two_factor_enabled_at' => 'datetime',
            'tour_visto_en'     => 'datetime',
            'password'          => 'hashed',
            'is_superadmin'     => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'current_location_id');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(BusinessLocation::class, 'user_locations')->withPivot('role_id')->withTimestamps();
    }

    // Otras empresas a las que este usuario entra (contador de varios clientes). La propia (business_id) es la de origen.
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'user_businesses')->withPivot('role_id')->withTimestamps();
    }

    public function empresasAccesibles(): \Illuminate\Support\Collection
    {
        $propia = $this->business_id ? Business::find($this->business_id) : null;
        $otras = $this->empresas()->whereNull('businesses.suspended_at')->get();
        return collect($propia ? [$propia] : [])->concat($otras)->unique('id')->sortBy('name')->values();
    }

    // Cambia la empresa activa: toma el rol asignado en esa empresa y su sucursal por defecto.
    public function cambiarEmpresa(Business $b): void
    {
        // La empresa de origen entra al listado con el rol actual, así al volver se recupera.
        if ($this->business_id && $this->business_id !== $b->id && ! $this->empresas()->where('businesses.id', $this->business_id)->exists()) $this->empresas()->syncWithoutDetaching([$this->business_id => ['role_id' => $this->role_id]]);
        $pivot = $this->empresas()->where('businesses.id', $b->id)->first()?->pivot;
        $rol = $pivot?->role_id ?: ($this->business_id === $b->id ? $this->role_id : null);
        $this->forceFill(['business_id' => $b->id, 'role_id' => $rol, 'current_location_id' => $b->locations()->where('is_default', true)->value('id') ?? $b->locations()->value('id')])->save();
        $this->unsetRelation('business'); $this->unsetRelation('role'); $this->unsetRelation('currentLocation');
    }

    public function esDueno(): bool
    {
        return $this->business && $this->business->owner_id === $this->id;
    }

    // Rol vigente: el asignado a la sucursal activa, si existe, o el general.
    public function rolActual(): ?Role
    {
        if ($this->current_location_id) {
            $pivot = $this->locations()->where('business_location_id', $this->current_location_id)->first()?->pivot;
            if ($pivot?->role_id) {
                return Role::find($pivot->role_id);
            }
        }
        return $this->role;
    }

    public function puede(string $modulo, string $accion = 'ver'): bool
    {
        if ($this->is_superadmin || $this->esDueno()) {
            return true;
        }
        if (! $this->business?->tieneModulo($modulo)) {
            return false;
        }
        return $this->rolActual()?->permite($modulo, $accion) ?? false;
    }

    public function modulosVisibles(): array
    {
        $delPlan = $this->business?->modulosActivos() ?? [];
        if ($this->is_superadmin || $this->esDueno()) {
            return $delPlan;
        }
        $delRol = $this->rolActual()?->modulosVisibles() ?? [];
        return array_values(array_intersect($delPlan, $delRol));
    }

    public function permisosResumen(): array
    {
        $rol = $this->rolActual();
        if ($this->is_superadmin || $this->esDueno() || ($rol && $rol->permisos === '*')) {
            return ['*' => config('erp.acciones')];
        }
        return is_array($rol?->permisos) ? $rol->permisos : [];
    }

    public function sucursalesAccesibles()
    {
        if ($this->is_superadmin || $this->esDueno()) {
            return $this->business?->locations()->where('is_active', true)->orderByDesc('is_default')->get() ?? collect();
        }
        $propias = $this->locations()->where('is_active', true)->orderByDesc('is_default')->get();
        return $propias->isEmpty()
            ? ($this->business?->locations()->where('is_default', true)->get() ?? collect())
            : $propias;
    }
}
