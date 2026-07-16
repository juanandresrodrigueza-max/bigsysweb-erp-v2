<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id','recipe_id','quantity','status',
        'scheduled_at','started_at','completed_at','notes','cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'cost' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function recipe() { return $this->belongsTo(Recipe::class); }
}
