<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ticket de soporte entre la empresa y el equipo de BigSys (superadmin).
class Ticket extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'asunto', 'categoria', 'prioridad', 'estado', 'mensajes', 'ultimo_mensaje_en', 'cerrado_en'];
    protected $casts = ['mensajes' => 'array', 'ultimo_mensaje_en' => 'datetime', 'cerrado_en' => 'datetime'];

    public const CATEGORIAS = ['consulta' => 'Consulta', 'error' => 'Algo no anda', 'sugerencia' => 'Sugerencia', 'facturacion' => 'Facturación / plan'];
    public const PRIORIDADES = ['baja' => 'Baja', 'normal' => 'Normal', 'alta' => 'Alta'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function numero(): string { return 'TK-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT); }

    public function agregar(string $de, ?User $u, string $texto): void
    {
        $m = $this->mensajes ?? [];
        $m[] = ['de' => $de, 'usuario' => $u?->name, 'texto' => $texto, 'fecha' => now()->toDateTimeString()];
        $this->mensajes = $m; $this->ultimo_mensaje_en = now();
        $this->estado = $de === 'soporte' ? 'respondido' : 'abierto';
        $this->save();
    }
}
