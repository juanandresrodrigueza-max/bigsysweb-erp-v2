<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'type', 'is_active', 'is_default', 'settings'];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'settings'   => 'array',
    ];
}
