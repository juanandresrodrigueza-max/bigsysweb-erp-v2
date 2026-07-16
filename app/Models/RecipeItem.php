<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class RecipeItem extends Model
{
    protected $fillable = ['recipe_id','product_id','quantity','unit','notes'];

    protected $casts = ['quantity' => 'decimal:4'];

    public function recipe() { return $this->belongsTo(Recipe::class); }
    public function material() { return $this->belongsTo(Product::class, 'product_id'); }
}
