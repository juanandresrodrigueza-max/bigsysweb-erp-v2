<?php

namespace App\Services\IA;

use App\Mcp\Busca;
use App\Models\BigcitoMemoria;
use App\Models\User;
use Illuminate\Support\Str;

// Fase 119.5: la memoria de Bigcito. El usuario le dice «acordate que Pepe es Pérez SRL», «mi caja es la Caja Central»,
// «a López siempre le facturo con la lista 2», «hablame más corto», y Bigcito lo tiene presente en cada charla, por el
// panel, por WhatsApp y en las rutinas. Cada persona tiene la suya; el dueño puede dejar cosas para toda la empresa.
class MemoriaService
{
    public const MAX = 60;       // por persona y por empresa
    public const MAX_TEXTO = 300;

    // Lo que Bigcito tiene presente para este usuario: lo de la empresa primero, después lo suyo.
    public function para(User $u): array
    {
        return BigcitoMemoria::where(fn($w) => $w->whereNull('user_id')->orWhere('user_id', $u->id))->orderByRaw('CASE WHEN user_id IS NULL THEN 0 ELSE 1 END')->orderBy('id')->limit(self::MAX * 2)->get()
            ->map(fn($m) => ['id' => $m->id, 'texto' => $m->texto, 'de_la_empresa' => $m->user_id === null])->all();
    }

    public function puedeEmpresa(User $u): bool { return $u->esDueno() || $u->puede('configuracion', 'editar'); }

    // Guarda algo para recordar. Si ya había algo casi igual, lo reemplaza. Devuelve ['memoria' => …] o ['error' => …].
    public function recordar(User $u, string $texto, bool $paraLaEmpresa = false): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        $texto = trim(preg_replace('/^(?:acordate|record[aá]|ten[eé] en cuenta|anot[aá]|no te olvides)\s+(?:de\s+)?(?:que\s+)?/iu', '', $texto));
        if (mb_strlen($texto) < 4) return ['error' => 'Decime qué querés que recuerde.'];
        if ($paraLaEmpresa && ! $this->puedeEmpresa($u)) return ['error' => 'Para dejar algo que valga para toda la empresa tenés que ser el dueño o poder editar la configuración. Lo puedo recordar sólo para vos.'];
        $texto = Str::ucfirst(mb_substr($texto, 0, self::MAX_TEXTO));
        $q = fn() => BigcitoMemoria::query()->when($paraLaEmpresa, fn($w) => $w->whereNull('user_id'), fn($w) => $w->where('user_id', $u->id));
        $norm = fn(string $s) => Str::lower(Str::ascii($s));
        // Mismo tema (empieza igual hasta «es / son / :») → se pisa: «Pepe es Pérez SRL» reemplaza a «Pepe es Pérez Hnos».
        $clave = fn(string $s) => trim(preg_split('/\s+(?:es|son|se llama|significa|quiere decir)\s+|:/u', $norm($s), 2)[0]);
        $igual = $q()->get()->first(fn($m) => $norm($m->texto) === $norm($texto) || (str_contains($norm($texto), ' es ') && $clave($m->texto) === $clave($texto) && mb_strlen($clave($texto)) >= 3));
        if ($igual) { $igual->update(['texto' => $texto, 'creada_por' => $u->id]); return ['memoria' => $igual, 'reemplazo' => true]; }
        if ($q()->count() >= self::MAX) return ['error' => 'Ya tengo ' . self::MAX . ' cosas anotadas. Pedime que olvide alguna antes de sumar otra.'];
        return ['memoria' => BigcitoMemoria::create(['business_id' => $u->business_id, 'user_id' => $paraLaEmpresa ? null : $u->id, 'creada_por' => $u->id, 'texto' => $texto]), 'reemplazo' => false];
    }

    // Olvida por id o por el texto que más se parece. Lo de la empresa sólo lo borra quien puede dejarlo.
    public function olvidar(User $u, string $ref): array
    {
        $ref = trim($ref); if ($ref === '') return ['error' => 'Decime qué querés que olvide.'];
        $mias = BigcitoMemoria::where(fn($w) => $w->whereNull('user_id')->orWhere('user_id', $u->id))->get();
        $m = ctype_digit($ref) ? $mias->firstWhere('id', (int) $ref) : null;
        $m ??= $mias->first(fn($x) => Busca::parecido($x->texto, $ref)) ?? $mias->first(fn($x) => collect(preg_split('/\s+/u', $ref))->filter(fn($p) => mb_strlen($p) > 3)->contains(fn($p) => str_contains(Str::lower(Str::ascii($x->texto)), Str::lower(Str::ascii($p)))));
        if (! $m) return ['error' => "No tengo anotado nada parecido a «{$ref}»."];
        if ($m->user_id === null && ! $this->puedeEmpresa($u)) return ['error' => 'Eso está anotado para toda la empresa: lo puede borrar el dueño.'];
        $t = $m->texto; $m->delete();
        return ['olvidado' => $t];
    }

    // El bloque que va al prompt.
    public function paraPrompt(User $u): string
    {
        $l = $this->para($u);
        if (! $l) return 'todavía nada.';
        return "\n" . collect($l)->map(fn($m) => '- ' . $m['texto'] . ($m['de_la_empresa'] ? ' (vale para toda la empresa)' : ''))->implode("\n");
    }
}
