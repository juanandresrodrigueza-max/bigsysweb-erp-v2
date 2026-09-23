<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Rubro / familia de artículos (con un nivel de subrubro).
class Rubro extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'parent_id', 'nombre', 'color', 'orden'];

    public function parent(): BelongsTo { return $this->belongsTo(Rubro::class, 'parent_id'); }
    public function hijos(): HasMany { return $this->hasMany(Rubro::class, 'parent_id')->orderBy('orden')->orderBy('nombre'); }
    public function products(): HasMany { return $this->hasMany(Product::class); }

    public function nombreCompleto(): string
    {
        return $this->parent ? "{$this->parent->nombre} › {$this->nombre}" : $this->nombre;
    }
}
