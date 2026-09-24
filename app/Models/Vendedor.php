<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendedor extends Model
{
    use BelongsToBusiness;

    protected $table = 'vendedores';
    protected $fillable = ['business_id', 'user_id', 'nombre', 'email', 'telefono', 'comision_venta', 'comision_cobro', 'activo'];
    protected $casts = ['comision_venta' => 'decimal:2', 'comision_cobro' => 'decimal:2', 'activo' => 'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function comprobantes(): HasMany { return $this->hasMany(Comprobante::class); }
    public function cobros(): HasMany { return $this->hasMany(Cobro::class); }

    // El vendedor asociado al usuario logueado, si lo hay.
    public static function deUsuario(?int $userId): ?self
    {
        return $userId ? self::where('user_id', $userId)->where('activo', true)->first() : null;
    }
}
