<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Fase 96: un mensaje a muchos clientes de una vez (mail o WhatsApp), con lo que salió, lo que quedó para mandar a mano y lo que falló.
class Campana extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'canal', 'asunto', 'texto', 'filtro', 'destinatarios', 'enviados', 'pendientes', 'errores', 'terminada_en'];
    protected $casts = ['filtro' => 'array', 'terminada_en' => 'datetime'];

    public const CANALES = ['mail' => 'Mail', 'whatsapp' => 'WhatsApp'];
    public const VARIABLES = ['{cliente}' => 'nombre del cliente', '{saldo}' => 'saldo de cuenta corriente', '{empresa}' => 'tu empresa', '{portal}' => 'link al portal del cliente'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function envios(): HasMany { return $this->hasMany(Envio::class); }

    public function recontar(): void
    {
        $e = $this->envios();
        $this->forceFill(['enviados' => (clone $e)->where('estado', 'enviado')->count(), 'pendientes' => (clone $e)->where('estado', 'pendiente')->count(), 'errores' => (clone $e)->where('estado', 'error')->count()])->save();
    }
}
