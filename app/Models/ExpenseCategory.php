<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'color'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
