<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id','contact_id','service_id','assigned_to',
        'starts_at','ends_at','status','notes','price','location_id', 'nombre', 'telefono', 'email', 'personas', 'origen', 'token', 'comprobante_id', 'color', 'recordado_en',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'price' => 'decimal:2',
        'recordado_en' => 'datetime',
    ];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function service() { return $this->belongsTo(Product::class, 'service_id'); }
    public function assignedUser() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function comprobante() { return $this->belongsTo(Comprobante::class); }
    public function business() { return $this->belongsTo(Business::class); }

    public function scopeUpcoming($q) { return $q->where('starts_at', '>=', now())->where('status','confirmed'); }
    public function scopeForDate($q, $date) { return $q->whereDate('starts_at', $date); }
}
