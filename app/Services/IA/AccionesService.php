<?php

namespace App\Services\IA;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\Comprobantes\CobroService;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Fondos\FondosService;
use App\Services\Ventas\CobranzasService;

// Modo sin IA del asistente: entiende las frases más comunes y responde consultas en texto. Las acciones (y todo el modo con IA)
// pasan por el catálogo único App\Mcp\Catalogo, el mismo que usa el servidor MCP.
class AccionesService
{
    public const MEDIOS = ['efectivo', 'transferencia', 'mercadopago', 'tarjeta'];

    // ---- Consultas (se ejecutan al instante) ----
    public function consultar(string $nombre, array $a): string
    {
        $f = fn($n) => '$ ' . number_format((float) $n, 0, ',', '.');
        switch ($nombre) {
            case 'saldo_cliente':
                $c = $this->cliente($a['cliente'] ?? '');
                if (! $c) return "No encuentro un cliente parecido a \"{$a['cliente']}\".";
                $venc = (float) Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo');
                $ult = Comprobante::where('contact_id', $c->id)->emitidos()->facturas()->orderByDesc('fecha')->limit(3)->get()->map(fn($x) => "{$x->numeroFormateado()} del {$x->fecha->format('d/m')} por {$f($x->total)}" . ((float) $x->saldo > 0 ? " (debe {$f($x->saldo)})" : ''))->implode('; ');
                return "{$c->name}: saldo {$f($c->balance)}" . ($venc > 0 ? ", vencido {$f($venc)}" : ', nada vencido') . '. ' . ($ult ? "Últimas facturas: {$ult}." : 'Sin facturas.') . " Ficha: /clientes/{$c->id}";
            case 'stock_articulo':
                $p = $this->articulo($a['articulo'] ?? '');
                if (! $p) return "No encuentro un artículo parecido a \"{$a['articulo']}\".";
                return "{$p->name} ({$p->sku}): stock " . rtrim(rtrim(number_format((float) $p->stock, 3, ',', '.'), '0'), ',') . " {$p->unit}, mínimo " . (float) $p->stock_min . ", precio lista 1 {$f($p->price)}, costo {$f($p->costoPesos())}. Ficha: /stock/{$p->id}";
            case 'deudores':
                $d = app(CobranzasService::class)->deudores((int) ($a['cantidad'] ?? 5))->take((int) ($a['cantidad'] ?? 5));
                if ($d->isEmpty()) return 'Nadie debe nada.';
                return "Deudores: " . $d->map(fn($x) => "{$x['nombre']} {$f($x['saldo'])}" . ($x['vencido'] > 0 ? " (vencido {$f($x['vencido'])}, {$x['dias']} días)" : ''))->implode('; ') . '. Pantalla: /clientes/cobranzas';
            case 'ventas_periodo':
                [$d, $h, $label] = match ($a['periodo'] ?? 'hoy') {
                    'ayer' => [today()->subDay(), today()->subDay(), 'ayer'], 'semana' => [today()->startOfWeek(), today(), 'esta semana'],
                    'mes' => [today()->startOfMonth(), today(), 'este mes'], 'mes_anterior' => [today()->subMonth()->startOfMonth(), today()->subMonth()->endOfMonth(), 'el mes pasado'], default => [today(), today(), 'hoy'],
                };
                $q = Comprobante::ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC', 'NCA', 'NCB', 'NCC'])->whereBetween('fecha', [$d->toDateString(), $h->toDateString()]);
                $tot = (float) $q->clone()->selectRaw("COALESCE(SUM(CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -total ELSE total END),0) s")->value('s'); $n = $q->clone()->facturas()->count();
                return "Ventas {$label}: {$f($tot)} en {$n} facturas.";
        }
        return 'No sé hacer eso.';
    }

    public function interpretarLocal(string $m): ?array
    {
        $t = mb_strtolower(trim($m));
        $num = fn($s) => (float) str_replace(['.', ','], ['', '.'], preg_replace('/[^\d.,]/', '', $s));
        if (preg_match('/(?:cobr[aáe]\w*|registr[aá]\w* (?:un |el )?cobro)\s+(?:de\s+)?\$?\s*([\d.,]+)\s+(?:a|de)\s+(.+?)(?:\s+(?:en|por)\s+(efectivo|transferencia|mercadopago|tarjeta))?\s*$/u', $t, $x)) return ['accion' => 'registrar_cobro', 'args' => ['cliente' => $x[2], 'monto' => $num($x[1]), 'medio' => $x[3] ?? 'efectivo']];
        if (preg_match('/(?:gast[oée]\w*|pagu[eé]|registr[aá]\w* (?:un )?gasto)\s+(?:de\s+)?\$?\s*([\d.,]+)\s+(?:de|en|por)\s+(.+?)(?:\s+(?:desde|de|con)\s+(caja|banco))?\s*$/u', $t, $x)) return ['accion' => 'registrar_gasto', 'args' => ['concepto' => trim($x[2]), 'monto' => $num($x[1]), 'cuenta' => $x[3] ?? 'caja']];
        if (preg_match('/(?:record[aá]\w*|avis[aá]\w*)\s+(?:a\s+)?(.+?)\s+(?:la deuda|que debe|lo que debe|su deuda)(?:\s+por\s+(whatsapp|mail))?/u', $t, $x)) return ['accion' => 'recordar_deuda', 'args' => ['cliente' => trim($x[1]), 'canal' => $x[2] ?? 'whatsapp']];
        if (preg_match('/presupuest\w*\s+(?:para|a)\s+(.+?)\s+(?:de|con|por)\s+(.+)$/u', $t, $x)) {
            $items = [];
            foreach (preg_split('/\s*(?:,|\by\b)\s*/u', $x[2]) as $parte) if (preg_match('/^([\d.,]+)\s+(?:de\s+)?(.+)$/u', trim($parte), $y)) $items[] = ['articulo' => trim($y[2]), 'cantidad' => $num($y[1])];
            if ($items) return ['accion' => 'crear_presupuesto', 'args' => ['cliente' => trim($x[1]), 'items' => $items]];
        }
        if (preg_match('/(?:cu[aá]nto (?:me )?debe|saldo de|deuda de)\s+(.+?)\??$/u', $t, $x)) return ['consulta' => 'saldo_cliente', 'args' => ['cliente' => trim($x[1])]];
        if (preg_match('/(?:stock de|cu[aá]nto\w* (?:hay|tengo|queda\w*) de)\s+(.+?)\??$/u', $t, $x)) return ['consulta' => 'stock_articulo', 'args' => ['articulo' => trim($x[1])]];
        if (preg_match('/deudores|qui[eé]n(?:es)? (?:me )?debe/u', $t)) return ['consulta' => 'deudores', 'args' => ['cantidad' => 5]];
        if (preg_match('/vend[ií]\w*.*(hoy|ayer|semana|mes pasado|mes)/u', $t, $x)) return ['consulta' => 'ventas_periodo', 'args' => ['periodo' => str_replace('mes pasado', 'mes_anterior', $x[1])]];
        return null;
    }

    private function cliente(string $nombre): ?Contact
    {
        $t = trim($nombre); if ($t === '') return null;
        $like = \App\Support\Sql::like();
        return Contact::customers()->where('is_active', true)->where(fn($w) => $w->where('name', $like, $t)->orWhere('cuit', $like, $t))->first()
            ?? Contact::customers()->where('is_active', true)->where('name', $like, "%{$t}%")->orderByRaw('LENGTH(name)')->first()
            ?? Contact::customers()->where('is_active', true)->where(function ($w) use ($t, $like) { foreach (array_filter(explode(' ', $t), fn($p) => mb_strlen($p) > 3) as $p) $w->where('name', $like, "%{$p}%"); })->first();
    }

    private function articulo(string $nombre): ?Product
    {
        $t = trim($nombre); if ($t === '') return null;
        $like = \App\Support\Sql::like();
        return Product::where('active', true)->where(fn($w) => $w->where('sku', $like, $t)->orWhere('barcode', $like, $t)->orWhere('name', $like, $t))->first()
            ?? Product::where('active', true)->where('name', $like, "%{$t}%")->orderByRaw('LENGTH(name)')->first()
            ?? Product::where('active', true)->where(function ($w) use ($t, $like) { foreach (array_filter(explode(' ', $t), fn($p) => mb_strlen($p) > 2) as $p) $w->where('name', $like, "%{$p}%"); })->orderByRaw('LENGTH(name)')->first();
    }
}
