<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use BelongsToBusiness, SoftDeletes;

    public const ESTADOS = ['pending' => 'Pendiente', 'in_progress' => 'En curso', 'completed' => 'Terminada', 'cancelled' => 'Cancelada'];

    protected $fillable = [
        'business_id', 'business_location_id', 'deposito_id', 'recipe_id', 'product_id', 'user_id', 'numero', 'quantity', 'cantidad_producida', 'status',
        'scheduled_at', 'started_at', 'completed_at', 'notes', 'cost', 'comprobante_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'cantidad_producida' => 'decimal:4',
        'cost' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function recipe() { return $this->belongsTo(Recipe::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function deposito() { return $this->belongsTo(Deposito::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function comprobante() { return $this->belongsTo(Comprobante::class); }

    public function numeroFormateado(): string { return sprintf('OP %06d', $this->numero ?? $this->id); }
    public function estadoLabel(): string { return self::ESTADOS[$this->status] ?? $this->status; }
}
