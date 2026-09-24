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

// El asistente que hace las cosas: consultas al instante y acciones (cobro, gasto, presupuesto, recordatorio) que primero se proponen
// y recién se ejecutan cuando el usuario confirma. Cada acción respeta los permisos del rol y queda en la auditoría.
class AccionesService
{
    public const MEDIOS = ['efectivo', 'transferencia', 'mercadopago', 'tarjeta'];

    // Herramientas para la IA (formato de la API de Anthropic). Las de consulta se ejecutan solas; las de acción piden confirmación.
    public function herramientas(User $u): array
    {
        $t = [
            ['name' => 'saldo_cliente', 'description' => 'Saldo, vencido y últimas facturas de un cliente por nombre aproximado.', 'input_schema' => ['type' => 'object', 'properties' => ['cliente' => ['type' => 'string']], 'required' => ['cliente']]],
            ['name' => 'stock_articulo', 'description' => 'Stock y precio de un artículo por nombre o código aproximado.', 'input_schema' => ['type' => 'object', 'properties' => ['articulo' => ['type' => 'string']], 'required' => ['articulo']]],
            ['name' => 'deudores', 'description' => 'Los clientes que más deben, con lo vencido.', 'input_schema' => ['type' => 'object', 'properties' => ['cantidad' => ['type' => 'integer']]]],
            ['name' => 'ventas_periodo', 'description' => 'Ventas netas de un período: hoy, ayer, semana, mes, mes_anterior.', 'input_schema' => ['type' => 'object', 'properties' => ['periodo' => ['type' => 'string', 'enum' => ['hoy', 'ayer', 'semana', 'mes', 'mes_anterior']]], 'required' => ['periodo']]],
        ];
        if ($u->puede('clientes', 'crear')) $t[] = ['name' => 'registrar_cobro', 'description' => 'Registra un cobro a un cliente (propuesta: el usuario confirma). Medios: efectivo, transferencia, mercadopago, tarjeta.', 'input_schema' => ['type' => 'object', 'properties' => ['cliente' => ['type' => 'string'], 'monto' => ['type' => 'number'], 'medio' => ['type' => 'string', 'enum' => self::MEDIOS]], 'required' => ['cliente', 'monto']]];
        if ($u->puede('fondos', 'crear')) $t[] = ['name' => 'registrar_gasto', 'description' => 'Registra un gasto pagado desde caja o banco (propuesta: el usuario confirma).', 'input_schema' => ['type' => 'object', 'properties' => ['concepto' => ['type' => 'string'], 'monto' => ['type' => 'number'], 'categoria' => ['type' => 'string'], 'cuenta' => ['type' => 'string', 'enum' => ['caja', 'banco']]], 'required' => ['concepto', 'monto']]];
        if ($u->puede('comprobantes', 'crear')) $t[] = ['name' => 'crear_presupuesto', 'description' => 'Arma un presupuesto en borrador para un cliente con artículos y cantidades (propuesta: el usuario confirma).', 'input_schema' => ['type' => 'object', 'properties' => ['cliente' => ['type' => 'string'], 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['articulo' => ['type' => 'string'], 'cantidad' => ['type' => 'number']], 'required' => ['articulo', 'cantidad']]]], 'required' => ['cliente', 'items']]];
        if ($u->puede('clientes', 'editar')) $t[] = ['name' => 'recordar_deuda', 'description' => 'Manda un recordatorio de la deuda vencida a un cliente por WhatsApp o mail (propuesta: el usuario confirma).', 'input_schema' => ['type' => 'object', 'properties' => ['cliente' => ['type' => 'string'], 'canal' => ['type' => 'string', 'enum' => ['whatsapp', 'mail']]], 'required' => ['cliente']]];
        return $t;
    }

    public static function esConsulta(string $nombre): bool
    {
        return in_array($nombre, ['saldo_cliente', 'stock_articulo', 'deudores', 'ventas_periodo'], true);
    }

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

    // ---- Acciones: propuesta (con todo resuelto y verificado) ----
    public function proponer(User $u, string $nombre, array $a): array
    {
        $f = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        switch ($nombre) {
            case 'registrar_cobro':
                abort_unless($u->puede('clientes', 'crear'), 403, 'No tenés permiso para registrar cobros.');
                $c = $this->cliente($a['cliente'] ?? ''); $monto = round((float) ($a['monto'] ?? 0), 2); $medio = in_array($a['medio'] ?? '', self::MEDIOS, true) ? $a['medio'] : 'efectivo';
                if (! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\". Decime el nombre como figura en Clientes."];
                if ($monto <= 0) return ['error' => '¿De cuánto es el cobro?'];
                $pend = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->get();
                $imput = []; $resto = $monto;
                foreach ($pend as $p) { if ($resto <= 0) break; $m = min($resto, (float) $p->saldo); $imput[] = ['comprobante_id' => $p->id, 'monto' => round($m, 2), 'numero' => $p->numeroFormateado()]; $resto = round($resto - $m, 2); }
                return ['accion' => 'registrar_cobro', 'titulo' => "Cobrar {$f($monto)} a {$c->name} en {$medio}", 'detalle' => ($imput ? 'Se imputa a ' . collect($imput)->map(fn($i) => "{$i['numero']} ({$f($i['monto'])})")->implode(', ') : 'Queda a cuenta (no tiene facturas pendientes)') . ($resto > 0 && $imput ? " y {$f($resto)} a cuenta" : '') . ". Saldo actual {$f($c->balance)}.", 'datos' => ['contact_id' => $c->id, 'monto' => $monto, 'medio' => $medio, 'imputaciones' => array_map(fn($i) => ['comprobante_id' => $i['comprobante_id'], 'monto' => $i['monto']], $imput)]];
            case 'registrar_gasto':
                abort_unless($u->puede('fondos', 'crear'), 403, 'No tenés permiso para registrar gastos.');
                $monto = round((float) ($a['monto'] ?? 0), 2); $concepto = trim((string) ($a['concepto'] ?? '')) ?: 'Gasto';
                if ($monto <= 0) return ['error' => '¿De cuánto es el gasto?'];
                $tipo = ($a['cuenta'] ?? 'caja') === 'banco' ? 'banco' : 'caja';
                $cuenta = CuentaFondos::where('activa', true)->where('tipo', $tipo)->where(fn($w) => $w->where('business_location_id', $u->current_location_id)->orWhereNull('business_location_id'))->orderByDesc('es_default')->first() ?? CuentaFondos::where('activa', true)->where('tipo', $tipo)->first();
                if (! $cuenta) return ['error' => "No hay una cuenta de tipo {$tipo} activa."];
                $cat = ! empty($a['categoria']) ? ExpenseCategory::where('name', \App\Support\Sql::like(), '%' . $a['categoria'] . '%')->first() : null;
                $cat ??= ExpenseCategory::where('name', \App\Support\Sql::like(), '%' . explode(' ', $concepto)[0] . '%')->first();
                return ['accion' => 'registrar_gasto', 'titulo' => "Gasto de {$f($monto)}: {$concepto}", 'detalle' => "Sale de {$cuenta->nombre}" . ($cat ? " · categoría {$cat->name}" : ' · sin categoría') . '.', 'datos' => ['cuenta_fondos_id' => $cuenta->id, 'monto' => $monto, 'concepto' => $concepto, 'expense_category_id' => $cat?->id]];
            case 'crear_presupuesto':
                abort_unless($u->puede('comprobantes', 'crear'), 403, 'No tenés permiso para crear comprobantes.');
                $c = $this->cliente($a['cliente'] ?? '');
                if (! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\"."];
                $items = []; $noEncontrados = [];
                foreach ((array) ($a['items'] ?? []) as $it) {
                    $p = $this->articulo($it['articulo'] ?? '');
                    if (! $p) { $noEncontrados[] = $it['articulo'] ?? '?'; continue; }
                    $items[] = ['product_id' => $p->id, 'nombre' => $p->name, 'cantidad' => (float) ($it['cantidad'] ?? 1), 'precio_unit' => $p->precioLista((int) ($c->lista_precios ?: 1)), 'descripcion' => $p->name, 'alicuota_iva' => (float) $p->iva, 'descuento' => (float) $c->descuento];
                }
                if (! $items) return ['error' => 'No encontré ninguno de los artículos: ' . implode(', ', $noEncontrados)];
                $total = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unit'] * (1 - $i['descuento'] / 100) * (1 + $i['alicuota_iva'] / 100), $items));
                return ['accion' => 'crear_presupuesto', 'titulo' => "Presupuesto para {$c->name} por {$f($total)}", 'detalle' => collect($items)->map(fn($i) => "{$i['cantidad']} × {$i['nombre']} a {$f($i['precio_unit'])}")->implode(', ') . ($noEncontrados ? '. No encontré: ' . implode(', ', $noEncontrados) : '') . '. Queda en borrador para revisar y emitir.', 'datos' => ['contact_id' => $c->id, 'items' => $items]];
            case 'recordar_deuda':
                abort_unless($u->puede('clientes', 'editar'), 403, 'No tenés permiso para enviar recordatorios.');
                $c = $this->cliente($a['cliente'] ?? '');
                if (! $c) return ['error' => "No encuentro un cliente parecido a \"{$a['cliente']}\"."];
                $venc = Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->orderBy('fecha_vto')->first() ?? Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->first();
                if (! $venc) return ['error' => "{$c->name} no tiene facturas pendientes."];
                $canal = ($a['canal'] ?? 'whatsapp') === 'mail' ? 'mail' : 'whatsapp';
                $destino = $canal === 'mail' ? $c->email : ($c->mobile ?: $c->phone);
                if (! $destino) return ['error' => "{$c->name} no tiene " . ($canal === 'mail' ? 'email' : 'teléfono') . ' cargado.'];
                return ['accion' => 'recordar_deuda', 'titulo' => "Recordar a {$c->name} la {$venc->nombreTipo()} {$venc->numeroFormateado()} ({$f($venc->saldo)}) por {$canal}", 'detalle' => "A {$destino}. El texto es el configurado en Cobranzas.", 'datos' => ['comprobante_id' => $venc->id, 'canal' => $canal]];
        }
        return ['error' => 'No sé hacer eso.'];
    }

    // ---- Ejecución (después de confirmar) ----
    public function ejecutar(User $u, string $accion, array $d): array
    {
        $f = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        switch ($accion) {
            case 'registrar_cobro':
                abort_unless($u->puede('clientes', 'crear'), 403);
                $c = Contact::findOrFail($d['contact_id']);
                $cobro = app(CobroService::class)->registrar($c, ['fecha' => today()->toDateString(), 'medios' => [['medio' => $d['medio'], 'monto' => $d['monto']]], 'imputaciones' => $d['imputaciones'] ?? [], 'notas' => 'Registrado por el asistente']);
                AuditLog::registrar('ia_ejecuto', $cobro, "El asistente registró el cobro {$cobro->numeroFormateado()} a {$c->name} por {$f($d['monto'])} (confirmado por {$u->name})");
                return ['ok' => true, 'texto' => "Listo: recibo {$cobro->numeroFormateado()} por {$f($d['monto'])} a {$c->name}. Saldo ahora {$f($c->fresh()->balance)}.", 'url' => "/clientes/{$c->id}"];
            case 'registrar_gasto':
                abort_unless($u->puede('fondos', 'crear'), 403);
                $cuenta = CuentaFondos::findOrFail($d['cuenta_fondos_id']);
                $m = app(FondosService::class)->registrar($cuenta, ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $d['expense_category_id'] ?? null, 'concepto' => $d['concepto'], 'egreso' => $d['monto']]);
                AuditLog::registrar('ia_ejecuto', $m, "El asistente registró un gasto de {$f($d['monto'])} ({$d['concepto']}) en {$cuenta->nombre} (confirmado por {$u->name})");
                return ['ok' => true, 'texto' => "Listo: gasto de {$f($d['monto'])} por {$d['concepto']} desde {$cuenta->nombre}. Saldo {$f($cuenta->fresh()->saldo)}.", 'url' => "/fondos?cuenta={$cuenta->id}"];
            case 'crear_presupuesto':
                abort_unless($u->puede('comprobantes', 'crear'), 403);
                $svc = app(ComprobanteService::class);
                $c = $svc->guardarBorrador(['contact_id' => $d['contact_id'], 'tipo' => 'PRE', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'items' => array_map(fn($i) => ['product_id' => $i['product_id'], 'descripcion' => $i['descripcion'], 'cantidad' => $i['cantidad'], 'precio_unit' => $i['precio_unit'], 'descuento' => $i['descuento'], 'alicuota_iva' => $i['alicuota_iva']], $d['items'])]);
                AuditLog::registrar('ia_ejecuto', $c, "El asistente armó el presupuesto #{$c->id} (confirmado por {$u->name})");
                return ['ok' => true, 'texto' => "Listo: presupuesto en borrador por {$f($c->total)}. Revisalo y emitilo.", 'url' => "/comprobantes/{$c->id}/editar"];
            case 'recordar_deuda':
                abort_unless($u->puede('clientes', 'editar'), 403);
                $comp = Comprobante::findOrFail($d['comprobante_id']);
                $e = app(CobranzasService::class)->recordar($comp, $d['canal']);
                AuditLog::registrar('ia_ejecuto', $comp, "El asistente mandó un recordatorio por {$d['canal']} de {$comp->nombreTipo()} {$comp->numeroFormateado()} (confirmado por {$u->name})");
                return ['ok' => true, 'texto' => $e->link ? 'Abrí el link para mandar el WhatsApp (no hay API configurada).' : 'Recordatorio enviado.', 'url' => $e->link ?: "/clientes/{$comp->contact_id}"];
        }
        abort(422, 'Acción desconocida.');
    }

    // ---- Modo sin IA: entiende las frases más comunes ----
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
