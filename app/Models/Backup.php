<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'archivo', 'bytes', 'origen', 'resumen', 'user_id'];
    protected $casts = ['resumen' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
