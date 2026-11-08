<?php

namespace App\Services\Contabilidad;

use App\Models\Business;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 58 · Cada gasto cae en su categoría solo (y por la categoría, en su cuenta contable): primero lo que la empresa ya
// enseñó, después reglas por palabras ("Edenor" → Luz), y lo que nunca vio lo resuelve la IA con las categorías de la empresa.
// Lo que la IA decide queda aprendido: no se le pregunta dos veces por lo mismo.
class ClasificadorGastosService
{
    // Palabras → categoría estándar. Se crea la categoría la primera vez que hace falta.
    public const REGLAS = [
        'Luz' => ['edenor', 'edesur', 'epec', 'edea', 'edemsa', 'edelap', 'edet', 'energia', 'electric'],
        'Gas' => ['metrogas', 'naturgy', 'ecogas', 'camuzzi', 'litoral gas', 'gasnor', ' gas '],
        'Agua' => ['aysa', 'aguas cordobesas', 'aguas ', 'obras sanitarias', 'cooperativa de agua'],
        'Internet y teléfono' => ['telecom', 'personal', 'movistar', 'claro', 'fibertel', 'telecentro', 'internet', 'celular', 'telefon'],
        'Alquiler' => ['alquiler', 'expensas', 'inmobiliaria'],
        'Combustible' => ['ypf', 'shell', 'axion', 'puma', 'nafta', 'gasoil', 'combustible', 'estacion de servicio'],
        'Impuestos y tasas' => ['afip', 'arca', 'monotributo', 'iibb', 'ingresos brutos', 'rentas', 'municipalidad', 'tasa', 'sircreb', 'ley 25413', 'imp.deb', 'imp.cred', 'impuesto'],
        'Gastos bancarios' => ['comision', 'mantenimiento de cuenta', 'mantenim', 'gasto bancario', 'costo transferencia', 'cargo', 'iva com', 'iva serv'],
        'Seguros' => ['seguro', 'aseguradora', 'sancor seguros', 'la caja', 'federacion patronal', 'art '],
        'Sueldos y cargas' => ['sueldo', 'jornal', 'aguinaldo', 'cargas sociales', 'f931', 'sindicato', 'obra social'],
        'Fletes y correo' => ['flete', 'correo', 'andreani', 'oca', 'cruz del sur', 'via cargo', 'envio', 'mensajeria', 'cadeteria'],
        'Publicidad' => ['publicidad', 'meta ads', 'facebook', 'instagram', 'google ads', 'mercado ads', 'cartel', 'imprenta'],
        'Mantenimiento y reparaciones' => ['mantenimiento', 'reparacion', 'service', 'plomer', 'electricista', 'repuesto'],
        'Limpieza' => ['limpieza', 'lavandina', 'detergente', 'papel higienico'],
        'Librería y oficina' => ['libreria', 'papeleria', 'resma', 'toner', 'cartucho', 'insumos de oficina'],
        'Honorarios' => ['honorario', 'contador', 'estudio contable', 'abogado', 'escribano'],
        'Software y suscripciones' => ['suscripcion', 'software', 'licencia', 'hosting', 'dominio', 'google workspace', 'microsoft', 'bigsys'],
        'Viáticos y comidas' => ['viatico', 'almuerzo', 'restaurante', 'hotel', 'peaje', 'estacionamiento', 'taxi', 'uber', 'cabify'],
    ];

    // Palabras que no dicen qué es el gasto (modalidad de pago, "factura", "pago"...): se sacan antes de armar la clave.
    public const RUIDO = ['pago', 'pagos', 'deb', 'debito', 'aut', 'automatico', 'transf', 'transferencia', 'factura', 'fact', 'fc', 'compra', 'cuota', 'ref', 'nro', 'num', 'del', 'de', 'la', 'el', 'los', 'las', 'por', 'para', 'con', 'mes', 'periodo', 'vto', 'venc', 'recibo'];

    // La clave de memoria: las dos primeras palabras con sentido ("Factura Edenor noviembre" → "edenor noviembre", "Amoladora Bosch 710" → "amoladora bosch").
    public static function clave(string $texto): string
    {
        $s = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto);
        $s = preg_replace(['/\d+/', '/[^a-z ]/'], ' ', $s);
        $todas = array_values(array_filter(explode(' ', $s), fn($w) => strlen($w) > 2));
        $utiles = array_values(array_filter($todas, fn($w) => ! in_array($w, self::RUIDO, true)));
        return implode(' ', array_slice($utiles ?: $todas, 0, 2));
    }

    private function categoria(Business $b, string $nombre): ExpenseCategory
    {
        return ExpenseCategory::withoutGlobalScopes()->firstOrCreate(['business_id' => $b->id, 'name' => $nombre], ['color' => '#6f6a62']);
    }

    // Una descripción → categoría sugerida, o null si no se puede decir con seguridad.
    public function sugerir(Business $b, string $texto, bool $usarIa = true): ?array
    {
        $texto = trim($texto); if ($texto === '') return null;
        $k = self::clave($texto); $apr = $b->conciliacion['categorias'] ?? [];
        if ($k !== '' && isset($apr[$k]) && ($cat = ExpenseCategory::withoutGlobalScopes()->where('business_id', $b->id)->find($apr[$k]))) return ['expense_category_id' => $cat->id, 'nombre' => $cat->name, 'origen' => 'aprendido', 'confianza' => 0.95];
        $t = ' ' . mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto) . ' ';
        foreach (self::REGLAS as $nombre => $palabras) foreach ($palabras as $p) if (str_contains($t, $p)) { $cat = $this->categoria($b, $nombre); return ['expense_category_id' => $cat->id, 'nombre' => $cat->name, 'origen' => 'regla', 'confianza' => 0.8]; }
        if ($usarIa) return $this->sugerirLote($b, [$texto])[0] ?? null;
        return null;
    }

    // Varias descripciones de una vez: lo aprendido y las reglas por cada una, y una sola llamada a la IA para el resto. Lo que la IA decide se aprende.
    public function sugerirLote(Business $b, array $textos): array
    {
        $out = []; $paraIa = [];
        foreach ($textos as $i => $t) { $s = $this->sugerir($b, $t, false); if ($s) $out[$i] = $s; elseif (trim($t) !== '') $paraIa[$i] = $t; }
        if (! $paraIa || ! config('services.anthropic.api_key')) return $out;
        $cats = ExpenseCategory::withoutGlobalScopes()->where('business_id', $b->id)->orderBy('name')->get(['id', 'name']);
        $lista = $cats->map(fn($c) => "{$c->id}|{$c->name}")->implode("\n") ?: '(todavía no hay categorías)';
        $porClave = []; foreach ($paraIa as $i => $t) $porClave[self::clave($t) ?: "#{$i}"] ??= $i; // una sola pregunta por concepto repetido
        $items = collect($porClave)->map(fn($i) => "{$i}|" . str_replace("\n", ' ', mb_substr($paraIa[$i], 0, 120)))->implode("\n");
        try {
            $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 1500,
                'system' => "Clasificás gastos de una PyME argentina ({$b->name}) en categorías contables. Para cada gasto elegí una categoría existente por id; si ninguna encaja de verdad, proponé una nueva con un nombre corto y genérico en \"nueva\" (ej. \"Herramientas\"), nunca el nombre del proveedor. Si no se puede saber qué es, devolvé null en ambos. Devolvé SOLO JSON: {\"items\":[{\"i\":0,\"id\":12,\"nueva\":null,\"confianza\":0.9}]}.\nCategorías existentes (id|nombre):\n{$lista}",
                'messages' => [['role' => 'user', 'content' => "Gastos (índice|descripción):\n{$items}"]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            foreach ((array) ($d['items'] ?? []) as $r) {
                $i = $r['i'] ?? null; if ($i === null || ! isset($paraIa[$i])) continue;
                $cat = ! empty($r['id']) ? $cats->firstWhere('id', (int) $r['id']) : null;
                if (! $cat && ! empty($r['nueva']) && is_string($r['nueva'])) { $cat = $this->categoria($b, mb_substr(trim($r['nueva']), 0, 60)); $cats->push($cat); }
                if (! $cat || (float) ($r['confianza'] ?? 1) < 0.5) continue;
                $out[$i] = ['expense_category_id' => $cat->id, 'nombre' => $cat->name, 'origen' => 'ia', 'confianza' => (float) ($r['confianza'] ?? 0.7)];
                $this->aprender($b, $paraIa[$i], $cat->id);
            }
        } catch (\Throwable $e) { Log::warning('Clasificador gastos IA: ' . $e->getMessage()); }
        foreach ($paraIa as $i => $t) if (! isset($out[$i]) && ($s = $this->sugerir($b, $t, false))) $out[$i] = $s; // los repetidos toman lo que se aprendió recién
        return $out;
    }

    // Lo que el usuario eligió (o la IA decidió) queda para la próxima vez que aparezca ese concepto.
    public function aprender(Business $b, string $texto, int $categoriaId): void
    {
        $k = self::clave($texto); if ($k === '') return;
        $cfg = $b->conciliacion ?? []; $cfg['categorias'][$k] = $categoriaId; $b->conciliacion = $cfg; $b->save();
    }
}
