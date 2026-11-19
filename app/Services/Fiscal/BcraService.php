<?php

namespace App\Services\Fiscal;

use App\Models\Contact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 120.2: Central de Deudores del Banco Central (API pública, por CUIT/CUIL/CDI).
// Dice en qué situación está una persona o empresa con cada banco y financiera, cuánto debe y si tiene cheques rechazados.
// Sirve para decidir antes de dar cuenta corriente o aceptar un cheque. Es información pública del BCRA: no se le avisa a nadie
// ni deja rastro para el consultado. Los montos de deuda vienen en miles de pesos y acá se pasan a pesos.
class BcraService
{
    public const SITUACIONES = [
        1 => 'Normal', 2 => 'Con seguimiento especial (riesgo bajo)', 3 => 'Con problemas (riesgo medio)',
        4 => 'Con alto riesgo de insolvencia', 5 => 'Irrecuperable', 6 => 'Irrecuperable por disposición técnica',
    ];
    public const HORAS_CACHE = 12;

    private function base(): string { return rtrim((string) config('services.bcra.base', 'https://api.bcra.gob.ar/centraldedeudores/v1.0'), '/'); }

    public static function limpiar(?string $cuit): string { return preg_replace('/\D/', '', (string) $cuit); }

    // Consulta completa de un CUIT. Devuelve ['ok' => true, …] o ['ok' => false, 'error' => '…'].
    public function consultar(?string $cuit, bool $forzar = false): array
    {
        $id = self::limpiar($cuit);
        if (strlen($id) !== 11) return ['ok' => false, 'error' => 'Para consultar el Banco Central hace falta el CUIT, CUIL o CDI completo (11 números).'];
        $clave = "bcra:{$id}";
        if (! $forzar && ($c = Cache::get($clave))) return $c + ['de_cache' => true];

        $deudas = $this->pedir("/Deudas/{$id}");
        if (isset($deudas['error'])) return ['ok' => false, 'error' => $deudas['error']];
        $hist = $this->pedir("/Deudas/Historicas/{$id}");
        $cheques = $this->pedir("/Deudas/ChequesRechazados/{$id}");

        $r = $this->armar($id, $deudas['results'] ?? null, isset($hist['error']) ? null : ($hist['results'] ?? null), isset($cheques['error']) ? null : ($cheques['results'] ?? null));
        if (isset($hist['error']) || isset($cheques['error'])) $r['parcial'] = true; // se consiguió la deuda actual pero no todo lo demás
        Cache::put($clave, $r, now()->addHours(self::HORAS_CACHE));
        return $r;
    }

    // GET al BCRA. 404 = «no hay datos para ese CUIT» (no figura): no es un error. Devuelve el JSON, ['results' => null] o ['error' => …].
    private function pedir(string $ruta): array
    {
        try {
            $r = Http::timeout(12)->acceptJson()->withOptions(['verify' => (bool) config('services.bcra.verificar_ssl', true)])->get($this->base() . $ruta);
        } catch (\Throwable $e) {
            Log::warning('BCRA: no respondió', ['ruta' => $ruta, 'e' => $e->getMessage()]);
            $ssl = str_contains($e->getMessage(), 'SSL') || str_contains($e->getMessage(), 'certificate');
            return ['error' => $ssl ? 'No se pudo validar el certificado del Banco Central. Avisale a BigSys (se resuelve en el servidor con BCRA_VERIFICAR_SSL).' : 'El Banco Central no respondió. Probá de nuevo en un rato.'];
        }
        if ($r->status() === 404) return ['results' => null];
        if ($r->status() === 400) return ['error' => 'El Banco Central dice que ese CUIT no es válido.'];
        if (! $r->successful()) return ['error' => 'El Banco Central respondió con un error (' . $r->status() . '). Probá de nuevo en un rato.'];
        return (array) $r->json();
    }

    // Junta las tres respuestas en algo que se pueda leer de un vistazo.
    public function armar(string $id, ?array $deudas, ?array $hist, ?array $cheques): array
    {
        $periodos = collect((array) ($deudas['periodos'] ?? []))->sortByDesc('periodo')->values();
        $ultimo = $periodos->first();
        $entidades = collect((array) ($ultimo['entidades'] ?? []))->map(fn($e) => [
            'entidad' => (string) ($e['entidad'] ?? '—'), 'situacion' => (int) ($e['situacion'] ?? 0), 'situacion_texto' => self::SITUACIONES[(int) ($e['situacion'] ?? 0)] ?? 'Sin dato',
            'monto' => round((float) ($e['monto'] ?? 0) * 1000, 2), 'dias_atraso' => (int) ($e['diasAtrasoPago'] ?? 0),
            'refinanciado' => (bool) ($e['refinanciaciones'] ?? false), 'juicio' => (bool) ($e['procesoJud'] ?? false) || (bool) ($e['situacionJuridica'] ?? false), 'en_revision' => (bool) ($e['enRevision'] ?? false),
        ])->sortByDesc('situacion')->values();
        $peor = (int) $entidades->max('situacion');
        $peor24 = (int) collect((array) ($hist['periodos'] ?? []))->flatMap(fn($p) => (array) ($p['entidades'] ?? []))->max(fn($e) => (int) ($e['situacion'] ?? 0));
        $peor24 = max($peor24, $peor);

        $det = collect((array) ($cheques['causales'] ?? []))->flatMap(fn($c) => collect((array) ($c['entidades'] ?? []))->flatMap(fn($e) => collect((array) ($e['detalle'] ?? []))->map(fn($d) => $d + ['causal' => (string) ($c['causal'] ?? '')])));
        $sinPagar = $det->filter(fn($d) => empty($d['fechaPago']));
        $ch = ['cantidad' => $det->count(), 'monto' => round((float) $det->sum(fn($d) => (float) ($d['monto'] ?? 0)), 2), 'sin_pagar' => $sinPagar->count(), 'monto_sin_pagar' => round((float) $sinPagar->sum(fn($d) => (float) ($d['monto'] ?? 0)), 2),
            'ultimo' => $det->max(fn($d) => (string) ($d['fechaRechazo'] ?? '')) ?: null, 'causales' => $det->pluck('causal')->filter()->unique()->values()->all()];

        $semaforo = ($peor >= 3 || $ch['sin_pagar'] > 0) ? 'rojo' : (($peor === 2 || $ch['cantidad'] > 0 || $peor24 >= 3) ? 'amarillo' : 'verde');
        $per = $ultimo['periodo'] ?? null; $perTxt = $per && strlen((string) $per) === 6 ? substr((string) $per, 4, 2) . '/' . substr((string) $per, 0, 4) : null;
        $total = round((float) $entidades->sum('monto'), 2);
        $pesos = fn($n) => '$ ' . number_format($n, 0, ',', '.');

        if (! $entidades->count() && ! $ch['cantidad']) $resumen = 'No figura con deudas en bancos ni financieras, ni con cheques rechazados.';
        else {
            $resumen = $entidades->count()
                ? 'Peor situación: ' . $peor . ', ' . mb_strtolower(self::SITUACIONES[$peor] ?? 'sin dato') . ', en ' . $entidades->count() . ($entidades->count() === 1 ? ' entidad' : ' entidades') . ', debe ' . $pesos($total) . ($perTxt ? " a {$perTxt}" : '') . '.'
                : 'No figura con deudas en bancos ni financieras.';
            if ($peor24 > $peor) $resumen .= " En los últimos 24 meses llegó a situación {$peor24}.";
            if ($ch['cantidad']) $resumen .= ' Cheques rechazados: ' . $ch['cantidad'] . ' por ' . $pesos($ch['monto']) . ($ch['sin_pagar'] ? ", {$ch['sin_pagar']} sin pagar" : ', todos pagados') . '.';
        }
        $consejo = match ($semaforo) {
            'rojo' => 'Riesgo alto: conviene no darle cuenta corriente ni aceptarle cheques sin una garantía.',
            'amarillo' => 'Con antecedentes: conviene un límite de crédito bajo y plazos cortos.',
            default => 'Sin antecedentes negativos en el sistema financiero.',
        };

        return ['ok' => true, 'cuit' => $id, 'denominacion' => (string) ($deudas['denominacion'] ?? $cheques['denominacion'] ?? $hist['denominacion'] ?? ''), 'periodo' => $perTxt,
            'peor' => $peor, 'peor_texto' => $peor ? (self::SITUACIONES[$peor] ?? 'Sin dato') : 'Sin deudas informadas', 'peor_24m' => $peor24, 'total' => $total,
            'entidades' => $entidades->take(20)->all(), 'cheques' => $ch, 'semaforo' => $semaforo, 'resumen' => $resumen, 'consejo' => $consejo, 'consultado_en' => now()->toDateTimeString()];
    }

    // Consulta por el CUIT del cliente o proveedor y deja el resultado guardado en su ficha.
    public function consultarContacto(Contact $c, bool $forzar = true): array
    {
        $r = $this->consultar($c->cuit, $forzar);
        if ($r['ok']) $c->forceFill(['bcra' => collect($r)->except('de_cache')->all(), 'bcra_en' => now()])->save();
        return $r;
    }

    // Una línea para el contexto de Bigcito y la ficha: «BCRA (18/11): situación 3 …».
    public static function linea(?Contact $c): ?string
    {
        if (! $c || ! $c->bcra || empty($c->bcra['resumen'])) return null;
        return 'BCRA (' . ($c->bcra_en ? \Carbon\Carbon::parse($c->bcra_en)->format('d/m/Y') : 'sin fecha') . '): ' . $c->bcra['resumen'];
    }
}
