<?php

namespace App\Services\IA;

use App\Models\Business;
use App\Models\Documento;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Fase 59 · Preguntarle a tus documentos: cada adjunto (PDF, Word, planilla, texto, foto) se lee una vez y queda su texto;
// después se busca por palabras y la IA responde en castellano con la fuente. Bigcito usa lo mismo por voz.
class DocumentosIaService
{
    private const MAX_TEXTO = 120000;
    private const VACIAS = ['como', 'para', 'que', 'una', 'uno', 'los', 'las', 'del', 'con', 'por', 'sin', 'el', 'la', 'de', 'en', 'un', 'se', 'al', 'lo', 'es', 'donde', 'cuando', 'cuanto', 'cuanta', 'dice', 'tiene', 'este', 'esta', 'documento', 'contrato'];

    private static function norm(string $t): string { return Str::lower(Str::ascii($t)); }
    private static function raiz(string $p): string { return mb_strlen($p) >= 5 ? mb_substr($p, 0, mb_strlen($p) - 2) : $p; }
    private static function contar(string $texto, string $raiz, bool $entera = false): int { return preg_match_all('/(?<![a-z0-9])' . preg_quote($raiz, '/') . ($entera ? '(?![a-z0-9])' : '') . '/', $texto); }

    // El texto del archivo, según lo que es. Las fotos sólo con IA (las lee el modelo); si no hay clave quedan para cuando la haya.
    public function extraer(Documento $d): ?string
    {
        if (! Storage::disk('local')->exists($d->archivo)) return null;
        $path = Storage::disk('local')->path($d->archivo); $mime = (string) $d->mime; $ext = strtolower(pathinfo($d->nombre, PATHINFO_EXTENSION) ?: pathinfo($d->archivo, PATHINFO_EXTENSION)); // el nombre original manda: un .docx se guarda como .zip si el servidor no lo reconoce
        $texto = null;
        try {
            if ($mime === 'application/pdf' || $ext === 'pdf') {
                try { $texto = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText(); } catch (\Throwable) {}
                if (trim(preg_replace('/\s+/u', '', (string) $texto)) === '' && ($bin = trim((string) @shell_exec('command -v pdftotext')))) $texto = (string) @shell_exec(escapeshellcmd($bin) . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
            } elseif (in_array($ext, ['txt', 'csv', 'md'], true) || str_starts_with($mime, 'text/')) {
                $texto = file_get_contents($path);
            } elseif ($ext === 'docx') {
                $texto = $this->deZip($path, ['word/document.xml'], fn($xml) => preg_replace('/<\/w:p>/', "\n", $xml));
            } elseif ($ext === 'xlsx') {
                $texto = $this->deZip($path, ['xl/sharedStrings.xml'], fn($xml) => preg_replace('/<\/si>/', "\n", $xml));
            } elseif (str_starts_with($mime, 'image/') && config('services.anthropic.api_key')) {
                $texto = $this->leerFoto($path, $mime);
            }
        } catch (\Throwable $e) { Log::warning("Documento {$d->id}: " . $e->getMessage()); }
        if ($texto === null) return null;
        $texto = trim(preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $texto));
        return $texto === '' ? null : mb_substr($texto, 0, self::MAX_TEXTO);
    }

    private function deZip(string $path, array $partes, callable $antes): ?string
    {
        $z = new \ZipArchive(); if ($z->open($path) !== true) return null;
        $out = ''; foreach ($partes as $p) if (($xml = $z->getFromName($p)) !== false) $out .= html_entity_decode(strip_tags($antes($xml)), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
        $z->close(); return $out;
    }

    private function leerFoto(string $path, string $mime): ?string
    {
        if (filesize($path) > 5 * 1024 * 1024) return null;
        $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'), 'max_tokens' => 3000,
            'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime === 'image/jpg' ? 'image/jpeg' : $mime, 'data' => base64_encode(file_get_contents($path))]],
                ['type' => 'text', 'text' => 'Transcribí TODO el texto que se lee en esta imagen, tal cual, respetando renglones y tablas (una fila por renglón, columnas separadas por " | "). Si no hay texto, describí en una línea qué muestra. Sin comentarios.']]]],
        ]);
        return $r->successful() ? trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n")) : null;
    }

    public function indexar(Documento $d): bool
    {
        $t = $this->extraer($d);
        $d->forceFill(['texto' => $t, 'indexado_en' => $t === null && str_starts_with((string) $d->mime, 'image/') && ! config('services.anthropic.api_key') ? null : now()])->save();
        return $t !== null;
    }

    // Los que todavía no se leyeron (los viejos, o fotos que esperaban la clave): de a pocos, cuando alguien pregunta.
    public function indexarPendientes(Business $b, int $max = 15): int
    {
        $n = 0; foreach (Documento::withoutGlobalScopes()->where('business_id', $b->id)->whereNull('indexado_en')->orderBy('id')->limit($max)->get() as $d) if ($this->indexar($d)) $n++;
        return $n;
    }

    // Busca en los documentos de la empresa (o de un cliente/proveedor/artículo, o de un documento) los pedazos que más coinciden.
    public function buscar(Business $b, string $q, ?string $type = null, ?int $id = null, ?int $docId = null, int $max = 6): array
    {
        $this->indexarPendientes($b);
        $qn = self::norm(trim($q)); if (mb_strlen($qn) < 2) return [];
        $palabras = array_values(array_filter(preg_split('/[^a-z0-9]+/', $qn), fn($p) => mb_strlen($p) >= 2 && ! in_array($p, self::VACIAS, true)));
        if (! $palabras) $palabras = array_values(array_filter(preg_split('/[^a-z0-9]+/', $qn)));
        $docs = Documento::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('texto')->with('adjuntable')
            ->when($type && $id, fn($w) => $w->where('adjuntable_type', $type)->where('adjuntable_id', $id))->when($docId, fn($w) => $w->where('id', $docId))->orderByDesc('fecha')->limit(300)->get();
        $res = [];
        foreach ($docs as $d) {
            $nombreN = self::norm($d->nombre);
            foreach ($this->pedazos((string) $d->texto) as $i => $pedazo) {
                $texto = self::norm($pedazo); $pts = 0; $hits = 0;
                foreach ($palabras as $p) {
                    $r = self::raiz($p); $entera = mb_strlen($p) <= 3; $n = self::contar($texto, $r, $entera);
                    if (self::contar($nombreN, $r, $entera)) $pts += 3;
                    if ($n) { $hits++; $pts += min(6, $n) + 2 * min(3, self::contar($texto, $p, $entera)); }
                }
                if ($docId && $pts === 0) $pts = 1; // en un documento puntual va todo, aunque la pregunta no repita sus palabras
                if ($pts === 0) continue;
                if (count($palabras) > 1 && $hits === count($palabras)) $pts += 8;
                if (str_contains($texto, $qn)) $pts += 12;
                $adj = $d->adjuntable; $de = $adj ? ($adj->name ?? $adj->nombre ?? null) : null;
                $res[] = ['documento_id' => $d->id, 'nombre' => $d->nombre, 'fecha' => $d->fecha?->format('d/m/Y'), 'de' => $de, 'fragmento' => trim($pedazo), 'puntos' => $pts, 'url' => "/documentos/{$d->id}/descargar?ver=1", 'orden' => $i];
            }
        }
        usort($res, fn($x, $y) => [$y['puntos'], $x['orden']] <=> [$x['puntos'], $y['orden']]);
        return array_slice($res, 0, $max);
    }

    // Pedazos de ~900 caracteres cortados en un salto de línea, para que la fuente se pueda leer.
    private function pedazos(string $texto): array
    {
        $out = []; $texto = trim($texto);
        while ($texto !== '') {
            if (mb_strlen($texto) <= 1000) { $out[] = $texto; break; }
            $corte = mb_strrpos(mb_substr($texto, 0, 1000), "\n"); if ($corte === false || $corte < 500) $corte = mb_strrpos(mb_substr($texto, 0, 1000), ' ') ?: 900;
            $out[] = mb_substr($texto, 0, $corte); $texto = trim(mb_substr($texto, $corte));
        }
        return array_slice($out, 0, 200);
    }

    // La pregunta en castellano. Con clave: la IA responde sólo con lo que dicen los documentos y cita de cuál lo sacó. Sin clave: los fragmentos.
    public function preguntar(Business $b, string $q, ?string $type = null, ?int $id = null, ?int $docId = null): array
    {
        $fuentes = $this->buscar($b, $q, $type, $id, $docId, 6);
        $key = config('services.anthropic.api_key');
        if (! $key || ! $fuentes) return ['respuesta' => null, 'fuentes' => $fuentes, 'ia' => (bool) $key];
        $ctx = collect($fuentes)->map(fn($f, $i) => '[' . ($i + 1) . "] {$f['nombre']}" . ($f['de'] ? " (de {$f['de']})" : '') . ($f['fecha'] ? " · {$f['fecha']}" : '') . "\n{$f['fragmento']}")->implode("\n\n");
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 700,
                'system' => "Respondés preguntas sobre los documentos adjuntos de una PyME argentina ({$b->name}): contratos, listas de precios, constancias, fichas técnicas. Usá SOLO lo que dicen los fragmentos; si no alcanza, decí qué falta. Español rioplatense, corto y concreto (montos, fechas, plazos tal cual figuran). Al final, en una línea, 'Fuente: ' con el nombre del documento (o documentos) de donde lo sacaste.",
                'messages' => [['role' => 'user', 'content' => "Fragmentos:\n\n{$ctx}\n\nPregunta: {$q}"]]]);
            $texto = $r->successful() ? trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n")) : null;
        } catch (\Throwable $e) { Log::warning('Documentos IA: ' . $e->getMessage()); $texto = null; }
        return ['respuesta' => $texto ?: null, 'fuentes' => $fuentes, 'ia' => true];
    }
}
