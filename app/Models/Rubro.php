<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Rubro / familia de artículos: categorías, subcategorías y todos los niveles que hagan falta.
class Rubro extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'parent_id', 'nombre', 'color', 'orden'];

    public function parent(): BelongsTo { return $this->belongsTo(Rubro::class, 'parent_id'); }
    public function hijos(): HasMany { return $this->hasMany(Rubro::class, 'parent_id')->orderBy('orden')->orderBy('nombre'); }
    public function products(): HasMany { return $this->hasMany(Product::class); }

    public function nombreCompleto(): string
    {
        return $this->parent ? $this->parent->nombreCompleto() . " › {$this->nombre}" : $this->nombre;
    }

    public function nivel(): int { return $this->parent ? $this->parent->nivel() + 1 : 0; }

    // Ids de este rubro y de todos sus descendientes (para filtrar artículos por categoría con sus subcategorías).
    public static function conDescendientes(int $id): array
    {
        $ids = [$id]; $frente = [$id];
        while ($frente) { $frente = self::whereIn('parent_id', $frente)->pluck('id')->all(); $ids = array_merge($ids, $frente); }
        return $ids;
    }

    // Árbol ordenado (padres antes que hijos) con nivel, para listados y selectores.
    public static function arbol()
    {
        $todos = self::orderBy('orden')->orderBy('nombre')->get();
        $out = collect();
        $rec = function ($padre, $nivel) use (&$rec, $todos, &$out) { foreach ($todos->where('parent_id', $padre) as $r) { $r->setAttribute('nivel', $nivel); $out->push($r); $rec($r->id, $nivel + 1); } };
        $rec(null, 0);
        return $out;
    }
}
