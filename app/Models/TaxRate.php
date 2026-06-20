<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'rate', 'type', 'is_active'];

    protected $casts = ['rate' => 'decimal:4', 'is_active' => 'boolean'];
}
