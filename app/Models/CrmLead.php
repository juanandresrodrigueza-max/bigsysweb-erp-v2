<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        "business_id","contact_id","assigned_to","title","status",
        "source","value","currency","probability","expected_close_date",
        "notes","lost_reason",
    ];

    protected $casts = [
        "value" => "decimal:2",
        "probability" => "integer",
        "expected_close_date" => "date",
    ];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function assignedUser() { return $this->belongsTo(User::class, "assigned_to"); }
    public function activities() { return $this->hasMany(CrmActivity::class, "lead_id"); }
    public function scopeOpen($q) { return $q->whereNotIn("status", ["won","lost"]); }
}
