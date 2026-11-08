<?php

namespace App\Services\Comprobantes;

use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use App\Services\Contabilidad\ConciliacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Transferencias de clientes → recibo. Desde el extracto del banco (lo que está en el banco y no en el sistema)
// o desde la foto del comprobante de transferencia. El cliente se reconoce sólo por un dato exacto: CUIT, DNI o el nombre completo.
class TransferenciaClienteService
{
    public function __construct(private CobroService $cobros, private ConciliacionService $conc) {}

    // ¿De qué cliente es? CUIT (11 dígitos) → DNI (7-8) → nombre completo tal cual. Si hay dos posibles, no elige.
    public function cliente(?string $texto, ?string $cuit = null, ?string $nombre = null): array
    {
        $clientes = Contact::customers()->where('is_active', true)->where('name', '!=', 'Consumidor Final')->get(['id', 'name', 'cuit', 'document']);
        $dig = preg_replace('/\D/', '', (string) $cuit) ?: null;
        $txtDig = ' ' . preg_replace('/\D+/', ' ', preg_replace('/(?<=\d)[.\-](?=\d)/', '', (string) $texto)) . ' ';
        foreach (array_filter([$dig]) as $c) { $m = $clientes->filter(fn($x) => preg_replace('/\D/', '', (string) $x->cuit) === $c); if ($m->count() === 1) return ['contact' => $m->first(), 'por' => 'CUIT']; }
        $m = $clientes->filter(fn($x) => strlen($d = preg_replace('/\D/', '', (string) $x->cuit)) === 11 && str_contains($txtDig, " {$d} "));
        if ($m->count() === 1) return ['contact' => $m->first(), 'por' => 'CUIT'];
        $m = $clientes->filter(function ($x) use ($txtDig, $dig) {
            $d = preg_replace('/\D/', '', (string) ($x->document ?: (strlen($c = preg_replace('/\D/', '', (string) $x->cuit)) === 11 ? substr($c, 2, 8) : '')));
            return strlen($d) >= 7 && (str_contains($txtDig, " {$d} ") || ($dig && strlen($dig) === 11 && substr($dig, 2, 8) === str_pad($d, 8, '0', STR_PAD_LEFT)));
        });
        if ($m->count() === 1) return ['contact' => $m->first(), 'por' => 'DNI'];
        $n = fn($s) => trim(preg_replace('/[^a-z0-9 ]+/', ' ', strtr(mb_strtolower((string) $s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'])));
        $hay = ' ' . preg_replace('/\s+/', ' ', $n(($nombre ?? '') . ' ' . $texto)) . ' ';
        $m = $clientes->filter(fn($x) => mb_strlen($nn = preg_replace('/\s+/', ' ', $n($x->name))) >= 6 && str_contains($hay, " {$nn} "));
        if ($m->count() === 1) return ['contact' => $m->first(), 'por' => 'nombre'];
        return ['contact' => null, 'por' => null];
    }

    // Facturas pendientes del cliente, de la más vieja a la más nueva, hasta cubrir el importe.
    public function imputaciones(Contact $c, float $monto): array
    {
        $out = []; $resta = $monto;
        foreach (Comprobante::ventas()->emitidos()->where('contact_id', $c->id)->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005)->orderBy('fecha')->orderBy('id')->get() as $f) {
            if ($resta <= 0.005) break;
            $x = round(min($resta, (float) $f->saldo), 2); $out[] = ['comprobante_id' => $f->id, 'monto' => $x, 'numero' => $f->numeroFormateado()]; $resta = round($resta - $x, 2);
        }
        return $out;
    }

    // Para la pantalla de conciliación: a quién corresponde cada transferencia que entró.
    public function sugerencias(CuentaFondos $cuenta): array
    {
        $out = [];
        foreach (ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'pendiente')->where('monto', '>', 0)->get() as $it) {
            $r = $this->cliente($it->descripcion . ' ' . $it->referencia);
            if ($r['contact']) $out[$it->id] = ['contact_id' => $r['contact']->id, 'cliente' => $r['contact']->name, 'por' => $r['por']];
        }
        return $out;
    }

    // Arma el recibo de una transferencia del extracto y la deja conciliada con ese renglón.
    public function reciboDesdeExtracto(ExtractoItem $it, Contact $c): Cobro
    {
        abort_if($it->estado !== 'pendiente' || (float) $it->monto <= 0, 422, 'Ese renglón ya está conciliado o no es una entrada de plata.');
        return DB::transaction(function () use ($it, $c) {
            $cobro = $this->recibo($c, (float) $it->monto, $it->fecha->toDateString(), $it->cuenta_fondos_id, $it->referencia, 'Transferencia del extracto: ' . $it->descripcion);
            $mov = MovimientoFondos::where('origen', 'cobro')->where('origen_id', $cobro->id)->where('cuenta_fondos_id', $it->cuenta_fondos_id)->firstOrFail();
            $this->conc->vincular($it, $mov, 'recibo');
            return $cobro;
        });
    }

    public function recibo(Contact $c, float $monto, string $fecha, int $cuentaId, ?string $referencia, string $nota): Cobro
    {
        return $this->cobros->registrar($c, ['fecha' => $fecha, 'medios' => [['medio' => 'transferencia', 'monto' => $monto, 'cuenta_fondos_id' => $cuentaId, 'referencia' => $referencia]],
            'imputaciones' => array_map(fn($i) => ['comprobante_id' => $i['comprobante_id'], 'monto' => $i['monto']], $this->imputaciones($c, $monto)), 'notas' => $nota]);
    }

    // La foto (o PDF) del comprobante de transferencia: la IA saca los datos; el cliente se busca sólo por dato exacto.
    public function leerComprobante(string $contenido, string $mime): array
    {
        $key = config('services.anthropic.api_key');
        if (! $key) return ['ok' => false, 'aviso' => 'Para leer comprobantes con IA hay que cargar la clave de IA en Configuración → IA. Mientras tanto cargá el cobro a mano.'];
        $bloque = $mime === 'application/pdf' ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($contenido)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($contenido)]];
        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 600,
                'system' => 'Leés comprobantes de transferencia bancaria o de billetera de Argentina. Devolvé SOLO JSON: {"monto":0,"fecha":"YYYY-MM-DD","ordenante_nombre":"","ordenante_cuit":"","numero_operacion":"","banco":"","destinatario":"","es_transferencia":true}. El ordenante es quien manda la plata. Si un dato no se lee con seguridad, null. Nunca inventes números.',
                'messages' => [['role' => 'user', 'content' => [$bloque, ['type' => 'text', 'text' => 'Leé este comprobante.']]]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($d) || empty($d['monto'])) throw new \RuntimeException('sin monto: ' . mb_substr($raw, 0, 150));
        } catch (\Throwable $e) {
            Log::warning('Comprobante transferencia: ' . $e->getMessage());
            return ['ok' => false, 'aviso' => 'No pude leer el comprobante. Probá con una foto más nítida o cargá el cobro a mano.'];
        }
        $monto = round((float) $d['monto'], 2);
        $r = $this->cliente(($d['ordenante_nombre'] ?? '') . ' ' . ($d['ordenante_cuit'] ?? ''), $d['ordenante_cuit'] ?? null, $d['ordenante_nombre'] ?? null);
        $ref = $d['numero_operacion'] ?? null;
        $repetido = $ref ? \App\Models\CobroMedio::whereHas('cobro')->where('referencia', $ref)->where('monto', $monto)->exists() : false;
        return ['ok' => true, 'datos' => $d + ['monto' => $monto], 'contact_id' => $r['contact']?->id, 'cliente' => $r['contact']?->name, 'por' => $r['por'],
            'imputaciones' => $r['contact'] ? $this->imputaciones($r['contact'], $monto) : [], 'repetido' => $repetido];
    }
}
