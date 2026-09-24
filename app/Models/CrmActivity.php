<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class CrmActivity extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id','lead_id','user_id','type','title',
        'description','due_date','completed_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lead() { return $this->belongsTo(CrmLead::class, 'lead_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function scopePending($q) { return $q->whereNull('completed_at'); }
}
