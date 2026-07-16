<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id','product_id','name','yield_quantity','yield_unit',
        'instructions','is_active',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function items() { return $this->hasMany(RecipeItem::class); }
}
