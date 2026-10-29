<?php

namespace App\Services\Sueldos;

use App\Mail\DocumentoMail;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Empleado;
use App\Models\Liquidacion;
use App\Models\LiquidacionItem;
use App\Models\ReciboExterno;
use App\Services\Canales\WhatsappPedidosService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 38: cada empleado recibe su recibo por link, lo mira y lo firma desde el celular; queda la fecha, la IP y el navegador.
// Sirve igual para los recibos que liquidamos acá y para los que vienen de afuera en PDF (separados por hoja y repartidos por CUIL, DNI o legajo).
class RecibosFirmaService
{
    // ---- 38.1 Enviar y firmar ----
    public function firmaEmpleador(Business $b, string $firma, ?string $nombre): void
    {
        abort_unless(str_starts_with($firma, 'data:image/png;base64,'), 422, 'La firma tiene que ser una imagen.');
        $c = $b->sueldos ?? []; $c['firma_empleador'] = $firma; $c['firma_empleador_nombre'] = $nombre; $c['firma_empleador_en'] = now()->format('d/m/Y H:i');
        $b->sueldos = $c; $b->save();
        AuditLog::registrar('editar', $b, 'Firma del empleador cargada para los recibos de sueldo' . ($nombre ? " ({$nombre})" : ''));
    }

    public function url(Model $r): string
    {
        if (! $r->token) { $r->token = Str::random(40); $r->save(); }
        return url("/recibo/{$r->token}");
    }

    private function empleadoDe(Model $r): ?Empleado { return $r instanceof LiquidacionItem ? $r->empleado : $r->empleado; }

    private function periodoDe(Model $r): string
    {
        $p = $r instanceof LiquidacionItem ? $r->liquidacion?->periodo : $r->periodo;
        return $p ? \Carbon\Carbon::createFromFormat('Y-m', $p)->locale('es')->isoFormat('MMMM YYYY') : '';
    }

    // Manda el link por mail (si tiene) y por WhatsApp (por la API de la empresa o con el link para abrir).
    public function enviar(Model $r, Business $b): array
    {
        $e = $this->empleadoDe($r); abort_if(! $e, 422, 'El recibo no tiene empleado asignado.');
        $url = $this->url($r); $texto = "Hola {$e->nombre}! Ya está tu recibo de sueldo de {$this->periodoDe($r)} de {$b->name}. Miralo y firmalo acá: {$url}";
        $mail = false; $wa = null;
        if ($e->email) { try { Mail::to($e->email)->send(new DocumentoMail($b, "Tu recibo de sueldo · {$this->periodoDe($r)}", $texto)); $mail = true; } catch (\Throwable) {} }
        if ($e->telefono) $wa = app(WhatsappPedidosService::class)->responder($b, $e->telefono, $texto);
        $r->update(['enviado_en' => now()]);
        return ['empleado' => $e->nombre, 'mail' => $mail, 'whatsapp' => $wa['enviado'] ?? false, 'link_whatsapp' => $wa['link'] ?? null, 'url' => $url, 'sin_contacto' => ! $e->email && ! $e->telefono];
    }

    // Enviar todos los de una liquidación, o sólo a los que faltan firmar.
    public function enviarLiquidacion(Liquidacion $l, Business $b, bool $soloFaltan = false): array
    {
        $out = [];
        foreach ($l->items()->with('empleado', 'liquidacion')->get() as $it) { if ($soloFaltan && $it->firmado_en) continue; $out[] = $this->enviar($it, $b); }
        AuditLog::registrar('editar', $l, ($soloFaltan ? 'Reenvió' : 'Envió') . ' ' . count($out) . " recibos de {$l->periodoLabel()} para firmar");
        return $out;
    }

    public function porToken(string $token): array
    {
        $r = LiquidacionItem::with('empleado', 'liquidacion')->where('token', $token)->first() ?? ReciboExterno::withoutGlobalScopes()->with(['empleado' => fn($q) => $q->withoutGlobalScopes()])->where('token', $token)->firstOrFail();
        if ($r instanceof LiquidacionItem) $r->setRelation('empleado', Empleado::withoutGlobalScopes()->find($r->empleado_id));
        $bid = $r instanceof LiquidacionItem ? Liquidacion::withoutGlobalScopes()->find($r->liquidacion_id)?->business_id : $r->business_id;
        return [$r, Business::find($bid)];
    }

    public function visto(Model $r): void { if (! $r->visto_en) $r->update(['visto_en' => now()]); }

    public function firmar(Model $r, string $firma, string $conformidad, ?string $obs, string $ip, ?string $agente): void
    {
        abort_if($r->firmado_en, 422, 'Este recibo ya está firmado.');
        abort_unless(str_starts_with($firma, 'data:image/png;base64,') && strlen($firma) > 200, 422, 'Firmá en el recuadro.');
        abort_unless(in_array($conformidad, ['conforme', 'disconforme'], true), 422);
        if ($conformidad === 'disconforme' && ! trim((string) $obs)) throw ValidationException::withMessages(['observacion' => 'Contanos en qué no estás de acuerdo.']);
        $r->update(['firma' => $firma, 'firmado_en' => now(), 'firma_ip' => $ip, 'firma_agente' => mb_substr((string) $agente, 0, 255), 'conformidad' => $conformidad, 'observacion' => $obs ? mb_substr($obs, 0, 500) : null, 'visto_en' => $r->visto_en ?? now()]);
    }

    // El historial: quién, cuándo se mandó, cuándo lo vio y cuándo firmó, con la IP.
    public function historial(?string $periodo = null): array
    {
        $fila = fn($r, $tipo) => ['tipo' => $tipo, 'id' => $r->id, 'empleado' => $r->empleado?->nombre ?? '— sin asignar —', 'cuil' => $r->empleado?->cuil, 'periodo' => $tipo === 'propio' ? $r->liquidacion?->periodo : $r->periodo, 'enviado' => $r->enviado_en?->format('d/m/Y H:i'), 'visto' => $r->visto_en?->format('d/m/Y H:i'), 'firmado' => $r->firmado_en?->format('d/m/Y H:i'), 'ip' => $r->firma_ip, 'conformidad' => $r->conformidad, 'observacion' => $r->observacion];
        $propios = LiquidacionItem::with('empleado', 'liquidacion')->whereHas('liquidacion', fn($q) => $q->when($periodo, fn($w) => $w->where('periodo', $periodo)))->get()->map(fn($r) => $fila($r, 'propio'));
        $ext = ReciboExterno::with('empleado')->when($periodo, fn($q) => $q->where('periodo', $periodo))->get()->map(fn($r) => $fila($r, 'externo'));
        return $propios->concat($ext)->sortBy([['periodo', 'desc'], ['empleado', 'asc']])->values()->all();
    }

    public function historialCsv(?string $periodo = null): string
    {
        $csv = "Empleado;CUIL;Período;Origen;Enviado;Visto;Firmado;IP;Conformidad;Observación\n";
        foreach ($this->historial($periodo) as $h) $csv .= implode(';', [$h['empleado'], $h['cuil'], $h['periodo'], $h['tipo'] === 'propio' ? 'Liquidado acá' : 'PDF externo', $h['enviado'], $h['visto'], $h['firmado'], $h['ip'], $h['conformidad'], str_replace([';', "\n"], [',', ' '], (string) $h['observacion'])]) . "\n";
        return $csv;
    }

    // ---- 38.2 Recibos que vienen de afuera ----
    // Separa el PDF por hoja, lee el texto de cada una y la reparte por CUIL, DNI o legajo. No sube dos veces el mismo archivo aunque lo renombren.
    public function importarPdf(string $path, string $nombre, ?string $periodo): array
    {
        $u = Auth::user(); $hash = sha1_file($path);
        if ($ya = ReciboExterno::where('hash_archivo', $hash)->first()) throw ValidationException::withMessages(['archivo' => "Ese PDF ya se subió el " . $ya->created_at->format('d/m/Y') . " (como «{$ya->archivo_origen}»). No se duplica aunque tenga otro nombre."]);
        $paginas = $this->separar($path);
        $empleados = Empleado::all(); $creados = []; $asig = 0;
        DB::transaction(function () use ($paginas, $empleados, $hash, $nombre, $periodo, $u, &$creados, &$asig) {
            foreach ($paginas as $n => $pg) {
                $hp = sha1($pg['pdf']);
                if (ReciboExterno::where('hash_pagina', $hp)->exists()) continue; // la misma hoja en otro archivo
                $dest = "sueldos/{$u->business_id}/externos/" . Str::random(20) . '.pdf'; Storage::put($dest, $pg['pdf']);
                [$emp, $por] = $this->identificar($pg['texto'], $empleados);
                $creados[] = ReciboExterno::create(['business_id' => $u->business_id, 'empleado_id' => $emp?->id, 'periodo' => $periodo ?? $this->periodoEnTexto($pg['texto']), 'archivo_origen' => $nombre, 'hash_archivo' => $hash, 'pagina' => $n + 1, 'hash_pagina' => $hp, 'path' => $dest, 'texto' => mb_substr($pg['texto'], 0, 20000), 'identificado_por' => $por, 'user_id' => $u->id, 'token' => Str::random(40)]);
                if ($emp) $asig++;
            }
        });
        AuditLog::registrar('crear', null, "Subió recibos de sueldo externos ({$nombre}): " . count($paginas) . " hojas, {$asig} asignadas");
        return ['hojas' => count($paginas), 'nuevas' => count($creados), 'asignadas' => $asig, 'sin_asignar' => count($creados) - $asig];
    }

    // Una hoja por recibo. FPDI arma cada hoja; si el PDF viene comprimido de una forma que FPDI no lee, se usa pdfseparate del sistema.
    public function separar(string $path): array
    {
        $out = [];
        try {
            $cuenta = (new \setasign\Fpdi\Fpdi())->setSourceFile($path);
            for ($i = 1; $i <= $cuenta; $i++) {
                $pdf = new \setasign\Fpdi\Fpdi(); $pdf->setSourceFile($path); $tpl = $pdf->importPage($i); $sz = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($sz['orientation'], [$sz['width'], $sz['height']]); $pdf->useTemplate($tpl);
                $out[] = ['pdf' => $pdf->Output('S'), 'texto' => ''];
            }
        } catch (\Throwable $e) {
            $out = [];
            $bin = trim((string) @shell_exec('command -v pdfseparate'));
            if (! $bin) throw ValidationException::withMessages(['archivo' => 'No pude separar las hojas de ese PDF. Probá exportarlo de nuevo como "PDF 1.4" o "compatible", o subí cada recibo por separado.']);
            $dir = sys_get_temp_dir() . '/sep_' . Str::random(8); @mkdir($dir);
            exec(escapeshellcmd($bin) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg("{$dir}/p-%d.pdf") . ' 2>/dev/null');
            $files = glob("{$dir}/p-*.pdf"); natsort($files);
            foreach ($files as $f) { $out[] = ['pdf' => file_get_contents($f), 'texto' => '']; @unlink($f); }
            @rmdir($dir);
        }
        if (! $out) throw ValidationException::withMessages(['archivo' => 'El PDF no tiene hojas.']);
        // El texto de cada hoja (para encontrar el CUIL): primero con el lector de PDF de PHP, si no con pdftotext.
        try { $pages = (new \Smalot\PdfParser\Parser())->parseFile($path)->getPages(); foreach ($pages as $k => $p) if (isset($out[$k])) $out[$k]['texto'] = $p->getText(); } catch (\Throwable) {}
        foreach ($out as $k => $o) if (trim($o['texto']) === '' && ($bin = trim((string) @shell_exec('command -v pdftotext')))) $out[$k]['texto'] = (string) @shell_exec(escapeshellcmd($bin) . ' -f ' . ($k + 1) . ' -l ' . ($k + 1) . ' ' . escapeshellarg($path) . ' - 2>/dev/null');
        return $out;
    }

    // CUIL (con o sin guiones), DNI, legajo y, por último, el nombre completo.
    public function identificar(string $texto, $empleados): array
    {
        $soloDig = preg_replace('/\D/', '', $texto);
        foreach ($empleados as $e) { $c = preg_replace('/\D/', '', (string) $e->cuil); if (strlen($c) === 11 && str_contains($soloDig, $c)) return [$e, 'cuil']; }
        foreach ($empleados as $e) { $d = preg_replace('/\D/', '', (string) ($e->documento ?: substr(preg_replace('/\D/', '', (string) $e->cuil), 2, 8))); if (strlen($d) >= 7 && preg_match('/(?<!\d)' . preg_quote($d, '/') . '(?!\d)/', preg_replace('/(?<=\d)\.(?=\d)/', '', $texto))) return [$e, 'dni']; }
        foreach ($empleados as $e) if ($e->legajo && preg_match('/legajo\s*(n[°º.]?|nro\.?)?\s*:?\s*0*' . preg_quote(ltrim((string) $e->legajo, '0'), '/') . '\b/iu', $texto)) return [$e, 'legajo'];
        $norm = fn($s) => trim(preg_replace('/\s+/', ' ', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s)));
        $t = $norm($texto);
        foreach ($empleados as $e) if (mb_strlen($e->nombre) > 5 && str_contains($t, $norm($e->nombre))) return [$e, 'nombre'];
        return [null, null];
    }

    private function periodoEnTexto(string $t): ?string
    {
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        if (preg_match('/\b(' . implode('|', array_keys($meses)) . ')\s*(de\s*)?(20\d\d)\b/iu', $t, $m)) return sprintf('%s-%02d', $m[3], $meses[mb_strtolower($m[1])]);
        if (preg_match('/per[ií]odo\s*:?\s*(\d{1,2})[\/\-](20\d\d)/iu', $t, $m)) return sprintf('%s-%02d', $m[2], $m[1]);
        return null;
    }

    public function asignar(ReciboExterno $r, ?int $empleadoId, ?string $periodo): void
    {
        $r->update(array_filter(['empleado_id' => $empleadoId, 'identificado_por' => $empleadoId ? 'a_mano' : null, 'periodo' => $periodo], fn($v) => $v !== null));
    }

    // ---- 38.3 Datos del período ----
    // La fecha de pago y el último depósito se cargan una vez y valen para todos los recibos del mes.
    public function datosPeriodo(string $periodo, array $d): int
    {
        $n = 0;
        foreach (Liquidacion::where('periodo', $periodo)->get() as $l) { $l->update(array_filter(['fecha' => $d['fecha_pago'] ?? null, 'deposito_fecha' => $d['deposito_fecha'] ?? null, 'deposito_banco' => $d['deposito_banco'] ?? null, 'deposito_periodo' => $d['deposito_periodo'] ?? null], fn($v) => $v !== null && $v !== '')); $n++; }
        return $n;
    }

    // Costo laboral de los últimos 12 meses: bruto, no remunerativo y contribuciones del empleador.
    public function costoLaboral(): array
    {
        $desde = today()->subMonths(11)->format('Y-m');
        $rows = Liquidacion::where('periodo', '>=', $desde)->where('estado', '!=', 'borrador')->get()->groupBy('periodo')->map(fn($g, $p) => ['periodo' => $p, 'bruto' => round((float) $g->sum('total_bruto'), 2), 'no_rem' => round((float) $g->sum('total_no_rem'), 2), 'contribuciones' => round((float) $g->sum('total_contribuciones'), 2), 'neto' => round((float) $g->sum('total_neto'), 2), 'costo' => round((float) $g->sum(fn($l) => (float) $l->total_bruto + (float) $l->total_no_rem + (float) $l->total_contribuciones), 2)])->sortKeys()->values();
        return ['meses' => $rows->all(), 'total' => round($rows->sum('costo'), 2), 'promedio' => $rows->count() ? round($rows->avg('costo'), 2) : 0];
    }
}
