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

    protected $fillable = ['business_id', 'parent_id', 'nombre', 'color', 'orden', 'iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda', 'cuenta_ventas_id', 'perc_iva', 'perc_iibb', 'imagen'];
    protected $casts = ['iva' => 'decimal:2', 'perc_iva' => 'decimal:2', 'perc_iibb' => 'decimal:2', 'perecedero' => 'boolean', 'seriado' => 'boolean', 'controla_stock' => 'boolean', 'control_turno' => 'boolean', 'en_tienda' => 'boolean'];

    // Datos que hereda el artículo. Nulo en el rubro = lo toma del rubro padre.
    public const HEREDABLES = ['iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda', 'cuenta_ventas_id', 'perc_iva', 'perc_iibb'];
    public const MARCAS_ARTICULO = ['iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda'];

    public function parent(): BelongsTo { return $this->belongsTo(Rubro::class, 'parent_id'); }
    public function hijos(): HasMany { return $this->hasMany(Rubro::class, 'parent_id')->orderBy('orden')->orderBy('nombre'); }
    public function products(): HasMany { return $this->hasMany(Product::class); }

    public function nombreCompleto(): string
    {
        return $this->parent ? $this->parent->nombreCompleto() . " › {$this->nombre}" : $this->nombre;
    }

    // Valores efectivos: los propios y, lo que falte, de los rubros de arriba (con el rubro de donde sale cada uno).
    public function efectivos(): array
    {
        $out = []; $r = $this; $vistos = [];
        while ($r && ! isset($vistos[$r->id])) {
            $vistos[$r->id] = true;
            foreach (self::HEREDABLES as $k) if (! array_key_exists($k, $out) && $r->getAttribute($k) !== null) $out[$k] = ['valor' => $this->plano($r, $k), 'de' => $r->id === $this->id ? null : $r->nombre];
            $r = $r->parent;
        }
        return $out;
    }

    public function valores(): array { return array_map(fn($x) => $x['valor'], $this->efectivos()); }

    private function plano(Rubro $r, string $k)
    {
        $v = $r->getAttribute($k);
        return in_array($k, ['iva', 'perc_iva', 'perc_iibb'], true) ? (float) $v : ($k === 'cuenta_ventas_id' ? (int) $v : $v);
    }

    public function imagenUrl(): ?string { return $this->imagen ? '/stock/rubros/' . $this->id . '/imagen' : null; }

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
