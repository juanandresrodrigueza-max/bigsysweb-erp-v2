<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $fillable = [
        'business_id', 'business_location_id', 'user_id', 'accion', 'modelo', 'modelo_id',
        'descripcion', 'antes', 'despues', 'ip', 'created_at',
    ];

    protected $casts = ['antes' => 'array', 'despues' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function registrar(string $accion, ?Model $modelo = null, ?string $descripcion = null, ?array $antes = null, ?array $despues = null): self
    {
        $user = Auth::user();
        return static::create([
            // El superadmin no tiene empresa: el registro queda en la empresa del modelo que tocó.
            'business_id'          => $user?->business_id ?? ($modelo instanceof Business ? $modelo->id : ($modelo?->business_id ?? null)),
            'business_location_id' => $user?->current_location_id,
            'user_id'              => $user?->id,
            'accion'               => $accion,
            'modelo'               => $modelo ? class_basename($modelo) : null,
            'modelo_id'            => $modelo?->getKey(),
            'descripcion'          => $descripcion,
            'antes'                => $antes,
            'despues'              => $despues,
            'ip'                   => request()?->ip(),
            'created_at'           => now(),
        ]);
    }
}
