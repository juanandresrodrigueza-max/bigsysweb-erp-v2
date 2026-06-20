<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InvoiceSequence extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'type', 'prefix', 'next_number', 'padding'];

    public function nextFormatted(): string
    {
        $number = str_pad($this->next_number, $this->padding, '0', STR_PAD_LEFT);
        return $this->prefix ? "{$this->prefix}-{$number}" : $number;
    }

    public static function generate(int $businessId, string $type): string
    {
        return DB::transaction(function () use ($businessId, $type) {
            $seq = static::where('business_id', $businessId)
                ->where('type', $type)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['business_id' => $businessId, 'type' => $type],
                    ['next_number' => 1, 'padding' => 8]
                );

            $formatted = $seq->nextFormatted();
            $seq->increment('next_number');
            return $formatted;
        });
    }
}
