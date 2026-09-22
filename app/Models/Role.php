<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['business_id', 'slug', 'nombre', 'descripcion', 'permisos', 'es_sistema'];

    protected $casts = ['permisos' => 'array', 'es_sistema' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permite(string $modulo, string $accion = 'ver'): bool
    {
        $p = $this->permisos;
        if ($p === '*' || (is_array($p) && ($p['*'] ?? null) === '*')) {
            return true;
        }
        if (! is_array($p)) {
            return false;
        }
        $global = $p['*'] ?? [];
        $propio = $p[$modulo] ?? [];
        return in_array($accion, (array) $propio, true) || in_array($accion, (array) $global, true);
    }

    public function modulosVisibles(): array
    {
        $p = $this->permisos;
        $todos = array_keys(config('erp.modulos'));
        if ($p === '*' || (is_array($p) && ($p['*'] ?? null) === '*')) {
            return $todos;
        }
        if (! is_array($p)) {
            return [];
        }
        if (isset($p['*'])) {
            return $todos;
        }
        return array_values(array_filter($todos, fn($m) => in_array('ver', (array) ($p[$m] ?? []), true)));
    }

    public static function crearRolesSistema(int $businessId): void
    {
        foreach (config('erp.roles_sistema') as $slug => $def) {
            static::firstOrCreate(
                ['business_id' => $businessId, 'slug' => $slug],
                ['nombre' => $def['nombre'], 'descripcion' => $def['descripcion'], 'permisos' => $def['permisos'], 'es_sistema' => true]
            );
        }
    }
}
