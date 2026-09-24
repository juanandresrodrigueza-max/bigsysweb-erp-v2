<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEntrega extends Model
{
    protected $table = 'webhook_entregas';
    protected $fillable = ['webhook_id', 'evento', 'payload', 'status', 'respuesta', 'ms'];
    protected $casts = ['payload' => 'array'];

    public function webhook(): BelongsTo { return $this->belongsTo(Webhook::class); }
}
