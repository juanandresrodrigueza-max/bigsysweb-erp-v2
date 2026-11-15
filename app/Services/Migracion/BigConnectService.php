<?php

namespace App\Services\Migracion;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Fase 41: migrar a un cliente de BigSys sin planillas. BIG Connect ya tiene sus datos leídos de los .TPS en el Hub;
// se piden por su API de sólo lectura (/api/v1/tabla/<nombre>, de a 5.000 filas) y entran por el mismo importador
// que los archivos, con el perfil BigSys. Primero una pasada de prueba que no guarda nada, después la de verdad;
// se puede repetir el día del corte: actualiza lo que ya está y no duplica.
class BigConnectService
{
    public const TOPE = 5000;
    // Lo que se trae, en el orden en que se carga (los saldos cruzan contra clientes y proveedores ya creados).
    public const PASOS = ['rubros' => 'Rubros y departamentos', 'vendedores' => 'Vendedores', 'proveedores' => 'Proveedores', 'clientes' => 'Clientes', 'articulos' => 'Artículos', 'empleados' => 'Empleados (legajos)',
        'fondos' => 'Cajas y bancos con su saldo', 'cheques' => 'Cheques en cartera', 'precios_proveedor' => 'Precios por proveedor', 'historial_ventas' => 'Ventas de los últimos 12 meses', 'saldos_clientes' => 'Saldos de clientes', 'saldos_proveedores' => 'Saldos de proveedores'];
    // Fase 101: los pasos que no van por el importador de planillas sino por su propia rutina.
    public const PROPIOS = ['rubros', 'vendedores', 'empleados', 'fondos', 'cheques', 'precios_proveedor', 'historial_ventas'];

    public function __construct(private ImportadorService $importador) {}

    public function config(Business $b): array
    {
        $c = $b->bigconnect ?? [];
        return ['url' => $c['url'] ?? null, 'empresa' => $c['empresa'] ?? null, 'conectado' => ! empty($c['clave']), 'clave' => ! empty($c['clave']) ? substr($c['clave'], 0, 11) . '…' : null, 'ultima' => $c['ultima'] ?? null];
    }

    // Guarda la conexión y prueba que la clave sirva: devuelve qué empresas y tablas ve.
    public function conectar(Business $b, string $url, ?string $clave, ?string $empresa = null): array
    {
        $url = rtrim(preg_replace('#/api/v1/?$#', '', trim($url)), '/');
        if (! preg_match('#^https?://#', $url)) throw ValidationException::withMessages(['url' => 'La dirección del Hub empieza con https:// (la ves en BIG Connect → Importar y exportar → API).']);
        $c = $b->bigconnect ?? [];
        $clave = trim((string) $clave) ?: ($c['clave'] ?? '');
        if (! preg_match('/^bk_[A-Za-z0-9]{8}_[A-Za-z0-9]{28}$/', $clave)) throw ValidationException::withMessages(['clave' => 'La clave de BIG Connect empieza con bk_ (se genera en Importar y exportar → API).']);
        $r = $this->pedir($url, $clave, 'tablas', $empresa ? ['empresa' => $empresa] : []);
        $faltan = array_diff(['productos', 'clientes', 'proveedores', 'cuenta_movimientos'], array_column($r['tablas'] ?? [], 'nombre'));
        if ($faltan) throw ValidationException::withMessages(['clave' => 'La clave no puede leer: ' . implode(', ', $faltan) . '. Generá una clave con acceso completo.']);
        $b->forceFill(['bigconnect' => ['url' => $url, 'clave' => $clave, 'empresa' => $empresa ?: ($r['empresa'] ?? null), 'ultima' => $c['ultima'] ?? null]])->save();
        AuditLog::registrar('editar', $b, 'Conectó BIG Connect para migrar (' . parse_url($url, PHP_URL_HOST) . ')');
        return ['negocio' => $r['negocio'] ?? null, 'empresa' => $r['empresa'] ?? null, 'tablas' => count($r['tablas'] ?? [])];
    }

    // Una tabla entera, de a páginas. $max corta antes (para la vista previa).
    public function tabla(Business $b, string $tabla, array $filtros = [], ?int $max = null): array
    {
        $c = $b->bigconnect ?? []; if (empty($c['clave'])) throw ValidationException::withMessages(['clave' => 'Primero conectá BIG Connect.']);
        $out = []; $desde = 0;
        do {
            $lim = $max ? min(self::TOPE, $max - count($out)) : self::TOPE;
            $r = $this->pedir($c['url'], $c['clave'], 'tabla/' . $tabla, $filtros + ['limite' => $lim, 'desde' => $desde] + (! empty($c['empresa']) ? ['empresa' => $c['empresa']] : []));
            $filas = $r['filas'] ?? []; $cols = $r['columnas'] ?? null;
            // La API devuelve filas como objetos o como listas con 'columnas' aparte: se normaliza a asociativo.
            foreach ($filas as $f) $out[] = $cols && array_is_list($f) ? array_combine($cols, $f) : (array) $f;
            $desde += count($filas);
        } while (count($filas) === $lim && (! $max || count($out) < $max) && $desde < 500000);
        return $out;
    }

    // Arma, para cada paso, las filas y el mapeo que entiende el importador.
    public function preparar(Business $b): array
    {
        $vendedores = $this->tabla($b, 'vendedores'); $vend = collect($vendedores)->pluck('nombre', 'codven')->all();
        $rubros = $this->tabla($b, 'rubros'); $rubroDe = collect($rubros)->keyBy(fn($r) => (string) $r['codrub'])->map(fn($r) => trim((string) ($r['depto'] ?? '')) !== '' ? trim($r['depto']) . ' / ' . trim((string) ($r['descripcion'] ?? '')) : trim((string) ($r['descripcion'] ?? '')))->all();
        $cuentas = $this->tabla($b, 'clientes');
        // La tabla de cuentas trae clientes (C), empleados (E) y, en instalaciones viejas, proveedores (P): se reparte por tipo.
        $cli = array_values(array_filter($cuentas, fn($r) => in_array(strtoupper((string) ($r['tipo'] ?? 'C')), ['C', ''], true)));
        $emp = array_values(array_filter($cuentas, fn($r) => strtoupper((string) ($r['tipo'] ?? '')) === 'E'));
        $prov = $this->tabla($b, 'proveedores'); $art = $this->tabla($b, 'productos');
        $movs = $this->tabla($b, 'cuenta_movimientos', ['saldo_pendiente__gt' => 0, 'anulado' => 0]);
        $iva = fn($x) => match (strtoupper(trim((string) $x))) { 'RI', 'R.I.' => 'Responsable Inscripto', 'MT', 'MO' => 'Monotributo', 'EXE', 'EX' => 'Exento', default => 'Consumidor Final' };
        $cuenta = fn($r, $tipo) => [(string) $r['codcta'], $tipo, $r['nombre'] ?? '', $r['cuit'] ?? '', $iva($r['condicion_iva'] ?? ''), $r['mail'] ?? '', $r['telefono'] ?? '', $r['direccion'] ?? '', $r['localidad'] ?? '', $r['provincia'] ?? ''];
        $mapCuenta = ['codigo', 'tipo_cuenta', 'nombre', 'cuit', 'condicion_iva', 'email', 'telefono', 'direccion', 'localidad', 'provincia'];
        $saldos = function ($tipo) use ($movs) {
            return collect($movs)->where('tipcta', $tipo)->map(fn($m) => [(string) $m['codcta'], trim(($m['tipcom'] ?? '') . ' ' . str_pad((string) ($m['pv'] ?? 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($m['nrocom'] ?? ''), 8, '0', STR_PAD_LEFT)), $m['fecha'] ?? '', $m['vencimiento'] ?: ($m['fecha'] ?? ''), (float) $m['saldo_pendiente']])->values()->all();
        };
        return [
            'proveedores' => ['filas' => array_map(fn($r) => $cuenta($r, 'P'), $prov), 'mapeo' => $mapCuenta],
            'clientes' => ['filas' => array_map(fn($r) => array_merge($cuenta($r, 'C'), [$r['lista_precios'] ?? '', $r['descuento'] ?? '', $vend[$r['vendedor'] ?? ''] ?? '', $r['limite_credito'] ?? '']), array_values(array_filter($cli, fn($r) => empty($r['contado'])))),
                'mapeo' => array_merge($mapCuenta, ['lista', 'descuento', 'vendedor', 'limite_credito'])],
            // Los precios del Hub ya son NETOS (sin IVA) y los artículos en dólares vienen pasados a pesos.
            'articulos' => ['filas' => array_map(fn($r) => [(string) $r['codart'], $r['codbar'] ?? '', $r['nombre'] ?? '', $rubroDe[(string) ($r['codrub'] ?? '')] ?? ($r['rubro'] ?? ''), $r['unidad'] ?? '', $r['costo'] ?? '', $r['precio1'] ?? '', $r['precio2'] ?? '', $r['precio3'] ?? '', $r['precio4'] ?? '', $r['precio5'] ?? '', $r['precio6'] ?? '', $r['iva_pct'] ?? 21, $r['stock'] ?? '', $r['stock_min'] ?? '', 'si'], $art),
                'mapeo' => ['codigo', 'barcode', 'descripcion', 'rubro', 'unidad', 'costo', 'precio1', 'precio2', 'precio3', 'precio4', 'precio5', 'precio6', 'iva', 'stock', 'stock_min', 'activo']],
            'saldos_clientes' => ['filas' => $saldos('C'), 'mapeo' => ['codigo', 'comprobante', 'fecha', 'vencimiento', 'importe']],
            'saldos_proveedores' => ['filas' => $saldos('P'), 'mapeo' => ['codigo', 'comprobante', 'fecha', 'vencimiento', 'importe']],
            'contado' => count($cli) - count(array_filter($cli, fn($r) => empty($r['contado']))),
            // Fase 101: lo que va por rutina propia (filas crudas del Hub).
            'rubros' => ['filas' => $rubros], 'vendedores' => ['filas' => $vendedores], 'empleados' => ['filas' => $emp],
            'fondos' => ['filas' => array_values(array_filter($this->tabla($b, 'fondos'), fn($f) => empty($f['contable']) && empty($f['valores'])))],
            'cheques' => ['filas' => array_values(array_filter($this->tabla($b, 'cheques'), fn($c) => in_array((string) ($c['estado'] ?? 'en_cartera'), ['en_cartera', 'vencido', 'cartera', ''], true)))],
            'precios_proveedor' => ['filas' => $this->tabla($b, 'proveedor_precios')],
            'historial_ventas' => ['filas' => array_values(array_filter($this->tabla($b, 'ventas_producto_dia', ['fecha__desde' => now()->subMonths(12)->startOfMonth()->toDateString()]), fn($v) => ($v['fecha'] ?? '') >= now()->subMonths(12)->startOfMonth()->toDateString()))],
        ];
    }

    // ---- Fase 101: los pasos propios. Cada uno devuelve [creadas, actualizadas, errores, detalle]. Repetibles: actualizan, no duplican. ----
    private function pasoPropio(Business $b, string $paso, array $filas): array
    {
        $c = 0; $a = 0; $e = 0; $det = [];
        $loc = \Illuminate\Support\Facades\Auth::user()?->current_location_id ?? \App\Models\BusinessLocation::withoutGlobalScopes()->where('business_id', $b->id)->value('id');
        $uid = \Illuminate\Support\Facades\Auth::id();
        $err = function (string $m) use (&$e, &$det) { $e++; if (count($det) < 8) $det[] = $m; };
        switch ($paso) {
            case 'rubros':
                foreach ($filas as $r) {
                    $nombre = trim((string) ($r['descripcion'] ?? '')); if ($nombre === '') { $err("Rubro {$r['codrub']} sin nombre"); continue; }
                    $padre = null; $depto = trim((string) ($r['depto'] ?? ''));
                    if ($depto !== '') $padre = \App\Models\Rubro::whereNull('parent_id')->whereRaw('lower(nombre) = ?', [mb_strtolower($depto)])->first() ?? \App\Models\Rubro::create(['business_id' => $b->id, 'nombre' => $depto]);
                    $rub = \App\Models\Rubro::where('parent_id', $padre?->id)->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->first();
                    $datos = ['business_id' => $b->id, 'parent_id' => $padre?->id, 'nombre' => $nombre, 'en_tienda' => empty($r['oculto'])];
                    if ($rub) { $rub->fill($datos)->save(); $a++; } else { \App\Models\Rubro::create($datos); $c++; }
                }
                break;
            case 'vendedores':
                foreach ($filas as $r) {
                    $nombre = trim((string) ($r['nombre'] ?? '')); if ($nombre === '') continue;
                    $v = \App\Models\Vendedor::whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->first();
                    $datos = ['business_id' => $b->id, 'nombre' => $nombre, 'comision_venta' => (float) ($r['com_venta'] ?? 0), 'comision_cobro' => (float) ($r['com_cobranza'] ?? 0), 'activo' => true];
                    if ($v) { $v->fill($datos)->save(); $a++; } else { \App\Models\Vendedor::create($datos); $c++; }
                }
                break;
            case 'empleados':
                $maxLegajo = (int) \App\Models\Empleado::withoutGlobalScopes()->where('business_id', $b->id)->max('legajo');
                foreach ($filas as $r) {
                    $nombre = trim((string) ($r['nombre'] ?? '')); if ($nombre === '') continue;
                    $cuil = preg_replace('/\D/', '', (string) ($r['cuit'] ?? ''));
                    $emp = ($cuil !== '' ? \App\Models\Empleado::whereRaw("replace(replace(coalesce(cuil,''),'-',''),' ','') = ?", [$cuil])->first() : null) ?? \App\Models\Empleado::whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->first();
                    $estado = mb_strtolower((string) ($r['estado'] ?? '')); $activo = ! in_array($estado, ['inactivo', 'baja', 'i', 'b'], true);
                    $datos = ['nombre' => $nombre, 'cuil' => $cuil !== '' ? (strlen($cuil) === 11 ? substr($cuil, 0, 2) . '-' . substr($cuil, 2, 8) . '-' . substr($cuil, 10) : $cuil) : ($emp?->cuil), 'email' => $r['mail'] ?? $emp?->email, 'telefono' => $r['telefono'] ?? $emp?->telefono, 'domicilio' => $r['direccion'] ?? $emp?->domicilio, 'localidad' => $r['localidad'] ?? $emp?->localidad, 'activo' => $activo, 'notas' => trim(($emp?->notas ? $emp->notas . ' · ' : '') . "BigSys cuenta {$r['codcta']}")];
                    if ($emp) { $emp->fill(array_filter($datos, fn($v) => $v !== null))->save(); $a++; continue; }
                    $legajo = (int) ($r['codcta'] ?? 0); if ($legajo <= 0 || \App\Models\Empleado::withoutGlobalScopes()->where('business_id', $b->id)->where('legajo', $legajo)->exists()) $legajo = ++$maxLegajo; else $maxLegajo = max($maxLegajo, $legajo);
                    \App\Models\Empleado::create($datos + ['business_id' => $b->id, 'business_location_id' => $loc, 'legajo' => $legajo, 'fecha_ingreso' => today()->toDateString(), 'sueldo_basico' => 0, 'modalidad' => 'mensual', 'jornada' => 1]); $c++;
                }
                if ($c) $det[] = 'Revisá en Sueldos la fecha de ingreso y el básico de cada legajo nuevo: BigSys no los trae.';
                break;
            case 'fondos':
                $fondos = app(\App\Services\Fondos\FondosService::class);
                foreach ($filas as $r) {
                    $nombre = trim((string) ($r['descri'] ?? '')); if ($nombre === '') continue;
                    $tipo = $this->tipoFondo($nombre, (string) ($r['tipval'] ?? ''));
                    $cta = \App\Models\CuentaFondos::whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->first();
                    if (! $cta) { $cta = \App\Models\CuentaFondos::create(['business_id' => $b->id, 'business_location_id' => $loc, 'tipo' => $tipo, 'nombre' => $nombre, 'moneda' => 'ARS', 'saldo' => 0, 'activa' => true, 'concilia' => (bool) ($r['concilia'] ?? false) || $tipo === 'banco']); $c++; } else $a++;
                    // El saldo de BigSys manda: la diferencia entra o sale como ajuste de migración (repetible el día del corte).
                    $dif = round((float) ($r['saldo'] ?? 0) - (float) $cta->fresh()->saldo, 2);
                    if (abs($dif) >= 0.01) $fondos->registrar($cta->fresh(), ['fecha' => today()->toDateString(), 'origen' => 'ajuste', 'concepto' => 'Saldo inicial migrado de BigSys', 'ingreso' => max(0, $dif), 'egreso' => max(0, -$dif), 'referencia' => 'bigsys-' . ($r['codfon'] ?? '')]);
                }
                break;
            case 'cheques':
                foreach ($filas as $r) {
                    $numero = trim((string) ($r['numero'] ?? '')); $monto = (float) ($r['importe'] ?? 0); if ($numero === '' || $monto <= 0) { $err('Cheque sin número o sin importe'); continue; }
                    $vto = $r['vencimiento'] ?: today()->toDateString(); $emision = $r['recibido'] ?: $vto;
                    $cli = ! empty($r['codcta']) ? \App\Models\Contact::customers()->where('codigo', (string) $r['codcta'])->first() : null;
                    $ch = \App\Models\Cheque::where('tipo', 'tercero')->where('numero', $numero)->where('monto', $monto)->first();
                    $datos = ['business_id' => $b->id, 'tipo' => 'tercero', 'numero' => $numero, 'banco' => $r['banco'] ?? null, 'emisor' => $r['cliente'] ?? ($cli?->name), 'fecha_emision' => $emision, 'fecha_pago' => $vto, 'monto' => $monto, 'echeq' => (bool) ($r['echeq'] ?? false), 'contact_id' => $cli?->id, 'notas' => 'Migrado de BigSys' . (! empty($r['nromov']) ? " (mov {$r['nromov']})" : '')];
                    if ($ch) { if ($ch->estado === 'cartera') $ch->fill($datos)->save(); $a++; } else { \App\Models\Cheque::create($datos + ['estado' => 'cartera']); $c++; }
                }
                break;
            case 'precios_proveedor':
                $prods = []; $provs = [];
                foreach ($filas as $r) {
                    $sku = (string) ($r['codart'] ?? ''); $cod = (string) ($r['codcta'] ?? ''); if ($sku === '' || $cod === '') continue;
                    $prods[$sku] ??= Product::withTrashed()->where('sku', $sku)->value('id'); $provs[$cod] ??= Contact::suppliers()->where('codigo', $cod)->value('id');
                    if (! $prods[$sku] || ! $provs[$cod]) { $err("Precio de {$sku} del proveedor {$cod}: artículo o proveedor no migrado"); continue; }
                    $pa = \App\Models\ProveedorArticulo::where('contact_id', $provs[$cod])->where('product_id', $prods[$sku])->first();
                    $datos = ['business_id' => $b->id, 'contact_id' => $provs[$cod], 'product_id' => $prods[$sku], 'codigo' => trim((string) ($r['codpro'] ?? '')) ?: $sku, 'descripcion' => null, 'ultimo_costo' => (float) ($r['precom'] ?? $r['precos'] ?? 0) ?: null, 'ultima_fecha' => $r['fecha'] ?: null];
                    if ($pa) { $pa->fill($datos)->save(); $a++; } else { \App\Models\ProveedorArticulo::create($datos); $c++; }
                    Product::withTrashed()->where('id', $prods[$sku])->whereNull('proveedor_id')->update(['proveedor_id' => $provs[$cod]]);
                }
                break;
            case 'historial_ventas':
                $prods = []; $lote = [];
                foreach ($filas as $r) {
                    $sku = (string) ($r['codart'] ?? ''); $fecha = (string) ($r['fecha'] ?? ''); if ($sku === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha)) continue;
                    $prods[$sku] ??= Product::withTrashed()->where('sku', $sku)->value('id'); if (! $prods[$sku]) { $e++; continue; }
                    $lote[] = ['business_id' => $b->id, 'product_id' => $prods[$sku], 'fecha' => substr($fecha, 0, 10), 'cantidad' => (float) ($r['cantidad'] ?? 0), 'importe' => (float) ($r['importe'] ?? 0), 'costo' => (float) ($r['costo'] ?? 0), 'origen' => 'bigsys', 'created_at' => now(), 'updated_at' => now()];
                    if (count($lote) >= 500) { \App\Models\VentaHistorica::upsert($lote, ['business_id', 'product_id', 'fecha', 'origen'], ['cantidad', 'importe', 'costo', 'updated_at']); $c += count($lote); $lote = []; }
                }
                if ($lote) { \App\Models\VentaHistorica::upsert($lote, ['business_id', 'product_id', 'fecha', 'origen'], ['cantidad', 'importe', 'costo', 'updated_at']); $c += count($lote); }
                if ($e) $det[] = "{$e} renglones de artículos que no están en el ERP (se saltearon)."; $e = 0;
                break;
        }
        return ['creadas' => $c, 'actualizadas' => $a, 'errores' => $e, 'detalle' => $det];
    }

    // Caja, banco, tarjeta o billetera, por el nombre y el tipo de valor de BigSys (EFE efectivo, CHQ cheques, CUP cupones, DOC documentos, BOL boletas).
    public function tipoFondo(string $nombre, string $tipval): string
    {
        $n = mb_strtolower($nombre);
        if (preg_match('/mercado ?pago|uala|ual\x{00e1}|billetera|mp\b|naranja x|modo\b/u', $n)) return 'billetera';
        if (preg_match('/tarj|visa|master|naranja|cabal|amex|cup[o\x{00f3}]n|posnet/u', $n) || strtoupper($tipval) === 'CUP') return 'tarjeta';
        if (preg_match('/banco|bco\.?|cta\.? ?cte|caja de ahorro|cuenta corriente|santander|galicia|naci[o\x{00f3}]n|macro|bbva|hsbc|credicoop|patagonia|supervielle|icbc|ciudad|provincia/u', $n)) return 'banco';
        return 'caja';
    }

    // Control de cierre: los totales de BigSys contra los del ERP, para apagar el viejo con confianza.
    public function cierre(Business $b): array
    {
        $cuentas = $this->tabla($b, 'clientes'); $prov = $this->tabla($b, 'proveedores'); $art = $this->tabla($b, 'productos'); $fon = array_filter($this->tabla($b, 'fondos'), fn($f) => empty($f['contable']) && empty($f['valores'])); $chq = $this->tabla($b, 'cheques');
        $cli = array_filter($cuentas, fn($r) => in_array(strtoupper((string) ($r['tipo'] ?? 'C')), ['C', ''], true) && empty($r['contado']));
        $fila = function (string $que, float $big, float $erp, bool $plata = true) { $dif = round($erp - $big, 2); return ['que' => $que, 'bigsys' => round($big, 2), 'erp' => round($erp, 2), 'diferencia' => $dif, 'ok' => abs($dif) < ($plata ? 1 : 0.5), 'plata' => $plata]; };
        $saldoCC = fn(string $tipo) => (float) \App\Models\CuentaCorriente::whereHas('contact', fn($q) => $q->whereIn('type', [$tipo, 'both'])->whereNotNull('codigo'))->selectRaw('COALESCE(SUM(debe - haber), 0) s')->value('s');
        $filas = [
            $fila('Clientes (sin la cuenta de contado)', count($cli), Contact::customers()->whereNotNull('codigo')->count(), false),
            $fila('Saldo de clientes', (float) array_sum(array_map(fn($r) => (float) ($r['saldo'] ?? 0), $cli)), $saldoCC('customer')),
            $fila('Proveedores', count($prov), Contact::suppliers()->whereNotNull('codigo')->count(), false),
            $fila('Saldo de proveedores', (float) array_sum(array_map(fn($r) => (float) ($r['saldo'] ?? 0), $prov)), $saldoCC('supplier')),
            $fila('Artículos', count($art), Product::count(), false),
            $fila('Inventario valorizado al costo', (float) array_sum(array_map(fn($r) => (float) ($r['stock'] ?? $r['stock_disp'] ?? 0) * (float) ($r['costo'] ?? 0), $art)), (float) Product::selectRaw('COALESCE(SUM(stock * cost), 0) s')->value('s')),
            $fila('Cheques en cartera', (float) array_sum(array_map(fn($r) => (float) ($r['importe'] ?? 0), array_filter($chq, fn($c) => in_array((string) ($c['estado'] ?? 'en_cartera'), ['en_cartera', 'vencido', 'cartera', ''], true)))), (float) \App\Models\Cheque::where('tipo', 'tercero')->where('estado', 'cartera')->sum('monto')),
        ];
        foreach ($fon as $f) { $nombre = trim((string) ($f['descri'] ?? '')); if ($nombre === '') continue; $filas[] = $fila("Saldo de {$nombre}", (float) ($f['saldo'] ?? 0), (float) (\App\Models\CuentaFondos::whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->value('saldo') ?? 0)); }
        return ['filas' => $filas, 'cierra' => collect($filas)->every(fn($f) => $f['ok']), 'diferencias' => collect($filas)->where('ok', false)->count(), 'hora' => now()->format('d/m/Y H:i')];
    }

    // Trae todo. Con $prueba = true lo hace adentro de una transacción que se deshace: dice qué pasaría sin guardar nada.
    // Los saldos van por comprobante (lo impago de cada factura, con su vencimiento), no el saldo suelto de la cuenta.
    public function migrar(Business $b, bool $prueba, array $pasos = []): array
    {
        $datos = $this->preparar($b);
        $pasos = $pasos ?: array_keys(self::PASOS);
        $res = [];
        DB::beginTransaction();
        try {
            foreach (self::PASOS as $k => $label) {
                if (! in_array($k, $pasos, true)) continue;
                if (in_array($k, self::PROPIOS, true)) { $r = $this->pasoPropio($b, $k, $datos[$k]['filas']); $res[] = ['paso' => $k, 'que' => $label, 'en_bigsys' => count($datos[$k]['filas'])] + $r; continue; }
                $imp = $this->importador->aplicar($k, $datos[$k]['filas'], $datos[$k]['mapeo'], ['archivo' => 'BIG Connect' . ($prueba ? ' (prueba)' : ''), 'saldos' => false, 'precios_con_iva' => false, 'ajustar_stock' => true, 'reemplazar_saldos' => true]);
                $res[] = ['paso' => $k, 'que' => $label, 'en_bigsys' => count($datos[$k]['filas']), 'creadas' => $imp->creadas, 'actualizadas' => $imp->actualizadas, 'errores' => $imp->errores, 'detalle' => array_slice((array) $imp->detalle, 0, 5)];
            }
            $control = ['clientes' => Contact::customers()->whereNotNull('codigo')->count(), 'proveedores' => Contact::suppliers()->whereNotNull('codigo')->count(), 'articulos' => Product::count()];
            if ($prueba) DB::rollBack(); else {
                DB::commit();
                $c = $b->bigconnect; $c['ultima'] = now()->format('d/m/Y H:i'); $b->forceFill(['bigconnect' => $c])->save();
                AuditLog::registrar('importar', $b, mb_substr('Migración desde BIG Connect: ' . collect($res)->map(fn($r) => "{$r['paso']} {$r['creadas']}+{$r['actualizadas']}")->implode(', '), 0, 250));
            }
        } catch (\Throwable $e) { DB::rollBack(); throw $e; }
        return ['prueba' => $prueba, 'pasos' => $res, 'cuenta_contado' => $datos['contado'], 'control' => $control,
            'diferencias' => collect($res)->filter(fn($r) => $r['errores'] > 0)->map(fn($r) => "{$r['que']}: {$r['errores']} con error")->values()->all()];
    }

    private function pedir(string $url, string $clave, string $ruta, array $q = []): array
    {
        try { $r = Http::withToken($clave)->acceptJson()->timeout(120)->get($url . '/api/v1/' . $ruta, $q); }
        catch (\Throwable $e) { throw ValidationException::withMessages(['url' => 'No se pudo llegar al Hub de BIG Connect: ' . $e->getMessage()]); }
        if ($r->status() === 401 || $r->status() === 403) throw ValidationException::withMessages(['clave' => 'BIG Connect rechazó la clave: ' . ($r->json('error') ?? 'sin permiso')]);
        if (! $r->successful()) throw ValidationException::withMessages(['url' => 'BIG Connect respondió ' . $r->status() . ': ' . ($r->json('error') ?? 'error')]);
        return $r->json() ?? [];
    }
}
