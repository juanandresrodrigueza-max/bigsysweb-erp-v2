<?php

namespace App\Services\Migracion;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Importacion;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\TipoCliente;
use App\Services\Stock\ImportacionPreciosService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Importa clientes, proveedores, artículos y saldos iniciales desde Excel/CSV (o los exports del BigSys viejo).
class ImportadorService
{
    public const ENTIDADES = [
        'clientes' => ['label' => 'Clientes', 'campos' => ['nombre' => 'Nombre / razón social', 'cuit' => 'CUIT / DNI', 'condicion_iva' => 'Condición IVA', 'email' => 'Email', 'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'localidad' => 'Localidad', 'provincia' => 'Provincia', 'lista' => 'Lista de precios (1-6)', 'dias_pago' => 'Días de pago', 'descuento' => 'Descuento % del cliente', 'vendedor' => 'Vendedor', 'limite_credito' => 'Límite de crédito', 'tipo_cliente' => 'Tipo de cliente', 'saldo' => 'Saldo inicial (debe +, a favor −)', 'notas' => 'Notas']],
        'proveedores' => ['label' => 'Proveedores', 'campos' => ['nombre' => 'Nombre / razón social', 'cuit' => 'CUIT', 'condicion_iva' => 'Condición IVA', 'email' => 'Email', 'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'localidad' => 'Localidad', 'provincia' => 'Provincia', 'saldo' => 'Saldo inicial (les debemos +)', 'notas' => 'Notas']],
        'articulos' => ['label' => 'Artículos', 'campos' => ['codigo' => 'Código / SKU', 'barcode' => 'Código de barras', 'descripcion' => 'Descripción', 'rubro' => 'Rubro', 'marca' => 'Marca', 'unidad' => 'Unidad', 'costo' => 'Costo', 'precio1' => 'Precio lista 1', 'precio2' => 'Precio lista 2', 'precio3' => 'Precio lista 3', 'precio4' => 'Precio lista 4', 'precio5' => 'Precio lista 5', 'precio6' => 'Precio lista 6', 'desc_cant_min' => 'Descuento por cantidad 1: desde', 'desc_cant_pct' => 'Descuento por cantidad 1: %', 'desc_cant2_min' => 'Descuento por cantidad 2: desde', 'desc_cant2_pct' => 'Descuento por cantidad 2: %', 'activo' => 'Activo (si/no)', 'tipo' => 'Tipo (producto/servicio)', 'iva' => 'IVA %', 'stock' => 'Stock inicial', 'stock_min' => 'Stock mínimo', 'proveedor' => 'Proveedor']],
        'saldos_clientes' => ['label' => 'Saldos de clientes (detalle por comprobante)', 'campos' => ['nombre' => 'Cliente', 'cuit' => 'CUIT / DNI', 'comprobante' => 'Comprobante (ej. FA 0001-00001234)', 'fecha' => 'Fecha', 'vencimiento' => 'Vencimiento', 'importe' => 'Importe pendiente (+ nos debe, − a favor)']],
        'saldos_proveedores' => ['label' => 'Saldos de proveedores (detalle por comprobante)', 'campos' => ['nombre' => 'Proveedor', 'cuit' => 'CUIT', 'comprobante' => 'Comprobante', 'fecha' => 'Fecha', 'vencimiento' => 'Vencimiento', 'importe' => 'Importe pendiente (+ le debemos, − a favor)']],
    ];

    // Encabezados de plantilla y de exportación: los mismos que entiende el importador (ida y vuelta sin retocar nada).
    public const PLANTILLAS = [
        'clientes' => ['Nombre', 'CUIT', 'Condicion IVA', 'Email', 'Telefono', 'Direccion', 'Localidad', 'Provincia', 'Lista', 'Limite de credito', 'Tipo de cliente', 'Dias de pago', 'Descuento %', 'Vendedor', 'Saldo', 'Notas'],
        'proveedores' => ['Nombre', 'CUIT', 'Condicion IVA', 'Email', 'Telefono', 'Direccion', 'Localidad', 'Provincia', 'Saldo', 'Notas'],
        'articulos' => ['Codigo', 'Codigo de barras', 'Descripcion', 'Rubro', 'Marca', 'Proveedor', 'Unidad', 'Costo', 'Precio lista 1', 'Precio lista 2', 'Precio lista 3', 'Precio lista 4', 'Precio lista 5', 'Precio lista 6', 'IVA', 'Stock', 'Stock minimo', 'Dto cant 1 desde', 'Dto cant 1 %', 'Dto cant 2 desde', 'Dto cant 2 %', 'Tipo', 'Activo'],
        'saldos_clientes' => ['Cliente', 'CUIT', 'Comprobante', 'Fecha', 'Vencimiento', 'Importe'],
        'saldos_proveedores' => ['Proveedor', 'CUIT', 'Comprobante', 'Fecha', 'Vencimiento', 'Importe'],
    ];
    public const EJEMPLOS = [
        'clientes' => ['Constructora Del Valle S.A.', '30-70012345-6', 'Responsable Inscripto', 'compras@delvalle.com', '351 555 1234', 'Av. Colon 1234', 'Cordoba', 'Cordoba', '2', '2000000', 'Mayorista', '30', '5', 'Vito Vendedor', '458900', ''],
        'proveedores' => ['Loma Negra S.A.', '30-50000000-1', 'Responsable Inscripto', 'ventas@lomanegra.com', '011 4000 0000', 'Ruta 3 km 12', 'Olavarria', 'Buenos Aires', '1250000', 'Entrega martes'],
        'articulos' => ['CEM50', '7790001000011', 'Cemento x 50 kg', 'Cementos y cales / Cementos', 'Loma Negra', 'Loma Negra S.A.', 'un', '7200', '9800', '9114', '8820', '8624', '8330', '8036', '21', '1500', '200', '10', '5', '50', '12', 'producto', 'si'],
        'saldos_clientes' => ['Constructora Del Valle S.A.', '30-70012345-6', 'FA 0001-00001234', '2026-08-15', '2026-09-14', '458900'],
        'saldos_proveedores' => ['Loma Negra S.A.', '30-50000000-1', 'FA 0003-00045678', '2026-09-01', '2026-09-30', '1250000'],
    ];

    public function __construct(private ImportacionPreciosService $lector, private StockService $stock) {}

    public function plantillaCsv(string $entidad): string
    {
        return "\xEF\xBB\xBF" . implode(';', self::PLANTILLAS[$entidad]) . "\n" . implode(';', self::EJEMPLOS[$entidad]) . "\n";
    }

    // Exporta lo que hay hoy con los mismos encabezados de la plantilla.
    public function exportarCsv(string $entidad): string
    {
        $f = fn($v) => str_replace([';', "\n", "\r"], [',', ' ', ' '], (string) ($v ?? ''));
        $n = fn($v) => $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', '');
        $out = "\xEF\xBB\xBF" . implode(';', self::PLANTILLAS[$entidad]) . "\n";
        if ($entidad === 'clientes') foreach (Contact::customers()->with('tipoCliente:id,nombre', 'vendedor:id,nombre')->orderBy('name')->get() as $c) $out .= implode(';', array_map($f, [$c->name, $c->cuit ?: $c->document, $c->condicion_iva, $c->email, $c->phone ?: $c->mobile, $c->address, $c->city, $c->province, $c->lista_precios, $n($c->credit_limit), $c->tipoCliente?->nombre, $c->dias_pago, $n($c->descuento), $c->vendedor?->nombre, $n($c->balance), $c->notes])) . "\n";
        if ($entidad === 'proveedores') foreach (Contact::suppliers()->orderBy('name')->get() as $c) $out .= implode(';', array_map($f, [$c->name, $c->cuit, $c->condicion_iva, $c->email, $c->phone, $c->address, $c->city, $c->province, $n($c->balance), $c->notes])) . "\n";
        if ($entidad === 'articulos') foreach (Product::with('rubro', 'proveedor:id,name')->orderBy('name')->get() as $p) $out .= implode(';', array_map($f, [$p->sku, $p->barcode, $p->name, $p->rubro ? str_replace(' › ', ' / ', $p->rubro->nombreCompleto()) : '', $p->marca, $p->proveedor?->name, $p->unit, $n($p->cost), $n($p->price), $n($p->prices['2'] ?? ''), $n($p->prices['3'] ?? ''), $n($p->prices['4'] ?? ''), $n($p->prices['5'] ?? ''), $n($p->prices['6'] ?? ''), $n($p->iva), $n($p->stock), $n($p->stock_min), $n($p->desc_cant_min), $n($p->desc_cant_pct), $n($p->desc_cant2_min), $n($p->desc_cant2_pct), $p->tipo, $p->active ? 'si' : 'no'])) . "\n";
        if (in_array($entidad, ['saldos_clientes', 'saldos_proveedores'], true)) {
            $dir = $entidad === 'saldos_clientes' ? 'venta' : 'compra';
            foreach (\App\Models\Comprobante::with('contact:id,name,cuit')->where('direccion', $dir)->where('estado', 'emitido')->where('saldo', '>', 0.005)->orderBy('fecha')->get() as $c) $out .= implode(';', array_map($f, [$c->contact?->name, $c->contact?->cuit, $c->nombreTipo() . ' ' . $c->numeroFormateado(), $c->fecha->toDateString(), $c->fecha_vto?->toDateString(), $n($c->saldo)])) . "\n";
            foreach (CuentaCorriente::with('contact:id,name,cuit,type')->where('tipo', 'saldo_inicial')->whereHas('contact', fn($q) => $q->whereIn('type', $entidad === 'saldos_clientes' ? ['customer', 'both'] : ['supplier', 'both']))->get() as $m) $out .= implode(';', array_map($f, [$m->contact?->name, $m->contact?->cuit, $m->concepto, $m->fecha->toDateString(), $m->fecha_vto?->toDateString(), $n((float) $m->debe - (float) $m->haber)])) . "\n";
        }
        return $out;
    }

    public function leer(string $path, string $nombre): array { return $this->lector->leer($path, $nombre); }

    public function sugerirMapeo(string $entidad, array $encabezado): array
    {
        $m = [];
        foreach ($encabezado as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            $campo = match (true) {
                $entidad === 'articulos' && (str_contains($h, 'barra') || str_contains($h, 'ean')) => 'barcode',
                $entidad === 'articulos' && (str_contains($h, 'cod') || str_contains($h, 'cód') || str_contains($h, 'sku')) => 'codigo',
                $entidad === 'articulos' && (str_contains($h, 'desc') || str_contains($h, 'nombre') || str_contains($h, 'detalle') || str_contains($h, 'art')) => 'descripcion',
                $entidad !== 'articulos' && (str_contains($h, 'nombre') || str_contains($h, 'razon') || str_contains($h, 'razón') || str_contains($h, 'cliente') || str_contains($h, 'proveedor')) => 'nombre',
                str_contains($h, 'cuit') || str_contains($h, 'dni') || str_contains($h, 'doc') => 'cuit',
                str_contains($h, 'iva') && (str_contains($h, 'cond') || str_contains($h, 'sit')) => 'condicion_iva',
                str_contains($h, 'iva') => 'iva',
                str_contains($h, 'mail') => 'email',
                str_contains($h, 'tel') || str_contains($h, 'cel') || str_contains($h, 'whats') => 'telefono',
                str_contains($h, 'dire') || str_contains($h, 'domic') || str_contains($h, 'calle') => 'direccion',
                str_contains($h, 'local') || str_contains($h, 'ciudad') => 'localidad',
                str_contains($h, 'prov') && $entidad === 'articulos' => 'proveedor',
                str_contains($h, 'prov') => 'provincia',
                str_starts_with($entidad, 'saldos') && (str_contains($h, 'import') || str_contains($h, 'saldo') || str_contains($h, 'pend') || str_contains($h, 'monto')) => 'importe',
                str_starts_with($entidad, 'saldos') && (str_contains($h, 'venc')) => 'vencimiento',
                str_starts_with($entidad, 'saldos') && (str_contains($h, 'fecha') || str_contains($h, 'emis')) => 'fecha',
                str_starts_with($entidad, 'saldos') && (str_contains($h, 'compro') || str_contains($h, 'factura') || str_contains($h, 'numero') || str_contains($h, 'número') || str_contains($h, 'nro')) => 'comprobante',
                str_contains($h, 'saldo') || str_contains($h, 'deuda') => 'saldo',
                str_contains($h, 'dto') && str_contains($h, '2') && (str_contains($h, 'desde') || str_contains($h, 'cant')) && ! str_contains($h, '%') => 'desc_cant2_min',
                str_contains($h, 'dto') && str_contains($h, '2') => 'desc_cant2_pct',
                str_contains($h, 'dto') && (str_contains($h, 'desde') || str_contains($h, 'cant')) && ! str_contains($h, '%') => 'desc_cant_min',
                str_contains($h, 'dto') && $entidad === 'articulos' => 'desc_cant_pct',
                (str_contains($h, 'descuento') || str_contains($h, 'dto')) && $entidad === 'clientes' => 'descuento',
                str_contains($h, 'dias') || str_contains($h, 'días') => 'dias_pago',
                str_contains($h, 'vended') => 'vendedor',
                str_contains($h, 'activo') => 'activo',
                $h === 'tipo' && $entidad === 'articulos' => 'tipo',
                str_contains($h, 'limite') || str_contains($h, 'límite') => 'limite_credito',
                str_contains($h, 'tipo') && $entidad === 'clientes' => 'tipo_cliente',
                str_contains($h, 'lista') && preg_match('/[2-6]/', $h) => 'precio' . preg_replace('/\D/', '', $h)[0],
                str_contains($h, 'lista') && $entidad === 'clientes' => 'lista',
                str_contains($h, 'rubro') || str_contains($h, 'familia') || str_contains($h, 'categor') => 'rubro',
                str_contains($h, 'marca') => 'marca',
                str_contains($h, 'unid') => 'unidad',
                str_contains($h, 'costo') => 'costo',
                str_contains($h, 'min') && str_contains($h, 'stock') => 'stock_min',
                str_contains($h, 'stock') || str_contains($h, 'exist') => 'stock',
                str_contains($h, 'precio') || str_contains($h, 'venta') || str_contains($h, 'pvp') => 'precio1',
                str_contains($h, 'nota') || str_contains($h, 'obs') => 'notas',
                default => null,
            };
            if ($campo && ! in_array($campo, $m, true)) $m[$i] = $campo;
        }
        return $m;
    }

    // Acepta 2026-08-15, 15/08/2026, 15-08-26 y números de serie de Excel.
    private function fecha(string $v): ?string
    {
        $v = trim($v); if ($v === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $v, $m)) { $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]; return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]); }
        if (is_numeric($v) && (float) $v > 20000) return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        try { return \Carbon\Carbon::parse($v)->toDateString(); } catch (\Throwable) { return null; }
    }

    public function aplicar(string $entidad, array $filas, array $mapeo, array $opt = []): Importacion
    {
        $user = Auth::user();
        $col = array_flip($mapeo); // campo => índice
        $num = function ($v) { $s = trim((string) $v); if ($s === '') return null; if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s); return (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $s)); };
        $val = fn($f, $campo) => isset($col[$campo]) ? trim((string) ($f[$col[$campo]] ?? '')) : '';
        $leidas = 0; $creadas = 0; $act = 0; $err = 0; $detalle = [];
        DB::transaction(function () use ($entidad, $filas, $opt, $num, $val, $user, &$leidas, &$creadas, &$act, &$err, &$detalle) {
            $rubros = Rubro::pluck('id', 'nombre')->mapWithKeys(fn($id, $n) => [mb_strtolower($n) => $id])->all();
            $tipos = TipoCliente::pluck('id', 'nombre')->mapWithKeys(fn($id, $n) => [mb_strtolower($n) => $id])->all();
            $provs = Contact::suppliers()->pluck('id', 'name')->mapWithKeys(fn($id, $n) => [mb_strtolower($n) => $id])->all();
            foreach ($filas as $i => $f) {
                $leidas++;
                try {
                    // Savepoint por fila: un error no tumba las demás (PostgreSQL aborta la transacción entera si no).
                    DB::transaction(function () use ($entidad, $f, $i, $opt, $num, $val, $user, &$creadas, &$act, &$err, &$detalle, &$rubros, &$tipos, &$provs) {
                    if ($entidad === 'articulos') {
                        $desc = $val($f, 'descripcion'); if ($desc === '') { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': sin descripción'; return; }
                        $sku = $val($f, 'codigo'); $bc = $val($f, 'barcode');
                        $p = ($sku !== '' ? Product::withTrashed()->where('sku', $sku)->first() : null) ?? ($bc !== '' ? Product::withTrashed()->where('barcode', $bc)->first() : null) ?? Product::withTrashed()->where('name', $desc)->first();
                        $nuevo = ! $p;
                        $p ??= new Product(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'tipo' => 'producto', 'active' => true, 'controla_stock' => true, 'moneda' => 'ARS']);
                        if ($p->trashed()) $p->restore();
                        // Rubro anidado: "Hierros / Aletado / Chicos" (también con ">" o "›") crea cada nivel si no existe.
                        $rub = $val($f, 'rubro'); $rubId = $p->rubro_id;
                        if ($rub !== '') { $padre = null; foreach (array_filter(array_map('trim', preg_split('/\s*[\/>›]\s*/u', $rub))) as $nivel) { $k = mb_strtolower($nivel) . '|' . ($padre ?? 0); $rubros[$k] ??= (Rubro::where('parent_id', $padre)->whereRaw('lower(nombre) = ?', [mb_strtolower($nivel)])->value('id') ?? Rubro::create(['business_id' => $user->business_id, 'parent_id' => $padre, 'nombre' => $nivel])->id); $padre = $rubros[$k]; } $rubId = $padre; }
                        $prov = $val($f, 'proveedor'); $provId = $prov !== '' ? ($provs[mb_strtolower($prov)] ?? ($provs[mb_strtolower($prov)] = Contact::create(['business_id' => $user->business_id, 'type' => 'supplier', 'name' => $prov, 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true, 'lista_precios' => 1])->id)) : $p->proveedor_id;
                        $prices = $p->prices ?? [];
                        foreach ([2, 3, 4, 5, 6] as $l) if (($v = $num($val($f, "precio{$l}"))) !== null) $prices[(string) $l] = $v;
                        $p->fill(array_filter([
                            'name' => $desc, 'sku' => $sku !== '' ? $sku : ($p->sku ?: null), 'barcode' => $bc !== '' ? $bc : $p->barcode, 'rubro_id' => $rubId, 'proveedor_id' => $provId, 'marca' => $val($f, 'marca') ?: $p->marca,
                            'unit' => $val($f, 'unidad') ? mb_strtolower(mb_substr($val($f, 'unidad'), 0, 10)) : ($p->unit ?: 'un'), 'cost' => $num($val($f, 'costo')) ?? $p->cost ?? 0, 'precio_compra' => $num($val($f, 'costo')) !== null ? $num($val($f, 'costo')) : $p->precio_compra, 'descuento_proveedor' => $num($val($f, 'costo')) !== null ? 0 : $p->descuento_proveedor, 'price' => $num($val($f, 'precio1')) ?? $p->price ?? 0,
                            'iva' => $num($val($f, 'iva')) ?? $p->iva ?? 21, 'stock_min' => $num($val($f, 'stock_min')) ?? $p->stock_min ?? 0,
                            'desc_cant_min' => $num($val($f, 'desc_cant_min')) ?? $p->desc_cant_min ?? 0, 'desc_cant_pct' => $num($val($f, 'desc_cant_pct')) ?? $p->desc_cant_pct ?? 0, 'desc_cant2_min' => $num($val($f, 'desc_cant2_min')) ?? $p->desc_cant2_min ?? 0, 'desc_cant2_pct' => $num($val($f, 'desc_cant2_pct')) ?? $p->desc_cant2_pct ?? 0,
                            'tipo' => in_array(mb_strtolower($val($f, 'tipo')), ['servicio', 'producto', 'insumo', 'elaborado'], true) ? mb_strtolower($val($f, 'tipo')) : $p->tipo,
                            'active' => $val($f, 'activo') !== '' ? ! in_array(mb_strtolower($val($f, 'activo')), ['no', '0', 'false', 'n'], true) : $p->active,
                        ], fn($v) => $v !== null) + ['prices' => $prices ?: null]);
                        if (! $p->sku) $p->sku = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($desc)), 0, 6)) . '-' . str_pad((string) (Product::withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT);
                        $p->save();
                        $st = $num($val($f, 'stock'));
                        if ($nuevo && $st !== null && $st > 0 && ($opt['stock_inicial'] ?? true)) $this->stock->entrada($p, $st, 'Stock inicial (importación)', null, null, (float) $p->cost);
                        // Artículos que ya existían: si se pide, el stock se ajusta al valor del archivo (entrada o salida por la diferencia).
                        elseif (! $nuevo && $st !== null && ($opt['ajustar_stock'] ?? false) && $p->controla_stock) { $dif = round($st - (float) $p->stock, 3); if ($dif > 0) $this->stock->entrada($p, $dif, 'Ajuste por importación', null, null, (float) $p->cost); elseif ($dif < 0) $this->stock->salida($p, -$dif, 'Ajuste por importación'); }
                        $nuevo ? $creadas++ : $act++;
                        return;
                    }
                    if (str_starts_with($entidad, 'saldos_')) {
                        // Saldo pendiente por comprobante: queda en la cuenta corriente con su fecha y vencimiento (sirve para mora, cobranzas y antigüedad).
                        $tipo = $entidad === 'saldos_clientes' ? 'customer' : 'supplier';
                        $nombre = $val($f, 'nombre'); $cuit = preg_replace('/\D/', '', $val($f, 'cuit'));
                        $c = ($cuit !== '' ? Contact::whereIn('type', [$tipo, 'both'])->whereRaw("replace(replace(cuit,'-',''),' ','') = ?", [$cuit])->first() : null) ?? ($nombre !== '' ? Contact::whereIn('type', [$tipo, 'both'])->whereRaw('lower(name) = ?', [mb_strtolower($nombre)])->first() : null);
                        if (! $c) { $err++; $detalle[] = 'Fila ' . ($i + 2) . ": no encuentro el " . ($tipo === 'customer' ? 'cliente' : 'proveedor') . " {$nombre} {$cuit} (importalo primero)"; return; }
                        $imp = $num($val($f, 'importe')); if ($imp === null || abs($imp) < 0.005) { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': sin importe'; return; }
                        $fecha = $this->fecha($val($f, 'fecha')) ?? ($opt['fecha_saldos'] ?? today()->toDateString()); $vto = $this->fecha($val($f, 'vencimiento')) ?? $fecha;
                        $comp = $val($f, 'comprobante') ?: 'Saldo';
                        if ($opt['reemplazar_saldos'] ?? false) { CuentaCorriente::where('contact_id', $c->id)->where('tipo', 'saldo_inicial')->where('concepto', $comp . ' (migración)')->delete(); }
                        CuentaCorriente::create(['business_id' => $c->business_id, 'contact_id' => $c->id, 'fecha' => $fecha, 'fecha_vto' => $vto, 'tipo' => 'saldo_inicial', 'concepto' => $comp . ' (migración)', 'debe' => $imp > 0 ? $imp : 0, 'haber' => $imp < 0 ? -$imp : 0]);
                        CuentaCorriente::recalcularSaldo($c->id);
                        $creadas++;
                        return;
                    }
                    // clientes / proveedores
                    $nombre = $val($f, 'nombre'); if ($nombre === '') { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': sin nombre'; return; }
                    $cuit = preg_replace('/\D/', '', $val($f, 'cuit'));
                    $tipo = $entidad === 'clientes' ? 'customer' : 'supplier';
                    $c = ($cuit !== '' ? Contact::where('type', $tipo)->whereRaw("replace(replace(cuit,'-',''),' ','') = ?", [$cuit])->first() : null) ?? Contact::where('type', $tipo)->where('name', $nombre)->first();
                    $nuevo = ! $c;
                    $c ??= new Contact(['business_id' => $user->business_id, 'type' => $tipo, 'is_active' => true, 'lista_precios' => 1]);
                    $cond = $val($f, 'condicion_iva'); $condN = match (true) { $cond === '' => null, str_contains(mb_strtolower($cond), 'mono') => 'Monotributista', str_contains(mb_strtolower($cond), 'exen') => 'Exento', str_contains(mb_strtolower($cond), 'insc') || str_contains(mb_strtolower($cond), 'ri') => 'Responsable Inscripto', default => 'Consumidor Final' };
                    $tc = $val($f, 'tipo_cliente'); $tcId = $tc !== '' ? ($tipos[mb_strtolower($tc)] ?? ($tipos[mb_strtolower($tc)] = TipoCliente::create(['business_id' => $user->business_id, 'nombre' => $tc, 'lista_precios' => 1])->id)) : null;
                    $vend = $val($f, 'vendedor'); $vendId = $vend !== '' ? \App\Models\Vendedor::whereRaw('lower(nombre) = ?', [mb_strtolower($vend)])->value('id') : null;
                    $c->fill(array_filter([
                        'name' => $nombre, 'cuit' => $cuit !== '' ? (strlen($cuit) === 11 ? substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10) : $cuit) : $c->cuit,
                        'condicion_iva' => $condN ?? $c->condicion_iva ?? ($cuit !== '' && strlen($cuit) === 11 ? 'Responsable Inscripto' : 'Consumidor Final'),
                        'email' => $val($f, 'email') ?: $c->email, 'phone' => $val($f, 'telefono') ?: $c->phone, 'address' => $val($f, 'direccion') ?: $c->address, 'city' => $val($f, 'localidad') ?: $c->city, 'province' => $val($f, 'provincia') ?: $c->province,
                        'lista_precios' => $entidad === 'clientes' ? min(6, max(1, (int) ($num($val($f, 'lista')) ?: ($c->lista_precios ?: 1)))) : 1, 'credit_limit' => $num($val($f, 'limite_credito')) ?? $c->credit_limit ?? 0, 'tipo_cliente_id' => $tcId ?? $c->tipo_cliente_id, 'notes' => $val($f, 'notas') ?: $c->notes,
                        'dias_pago' => $num($val($f, 'dias_pago')) !== null ? (int) $num($val($f, 'dias_pago')) : $c->dias_pago, 'descuento' => $num($val($f, 'descuento')) ?? $c->descuento, 'vendedor_id' => $vendId ?? $c->vendedor_id,
                    ], fn($v) => $v !== null));
                    $c->save();
                    $saldo = $num($val($f, 'saldo'));
                    if ($saldo !== null && ($opt['saldos'] ?? true) && ($nuevo || ($opt['reemplazar_saldos'] ?? false))) {
                        // Debe positivo = nos debe (cliente) / le debemos (proveedor). Se registra como movimiento de migración en la cuenta corriente.
                        // Si se pide reemplazar, se borra el saldo inicial anterior y se carga el nuevo (útil para migrar en varias tandas).
                        if (! $nuevo) CuentaCorriente::where('contact_id', $c->id)->where('tipo', 'saldo_inicial')->whereNull('comprobante_id')->delete();
                        if (abs($saldo) > 0.005) CuentaCorriente::create(['business_id' => $c->business_id, 'contact_id' => $c->id, 'fecha' => $opt['fecha_saldos'] ?? today()->toDateString(), 'tipo' => 'saldo_inicial', 'concepto' => 'Saldo inicial (migración)', 'debe' => $saldo > 0 ? $saldo : 0, 'haber' => $saldo < 0 ? -$saldo : 0]);
                        CuentaCorriente::recalcularSaldo($c->id);
                    }
                    $nuevo ? $creadas++ : $act++;
                    });
                } catch (\Throwable $e) { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': ' . $e->getMessage(); }
            }
        });
        $imp = Importacion::create(['business_id' => $user->business_id, 'user_id' => $user->id, 'entidad' => $entidad, 'archivo' => $opt['archivo'] ?? null, 'leidas' => $leidas, 'creadas' => $creadas, 'actualizadas' => $act, 'errores' => $err, 'detalle' => array_slice($detalle, 0, 100)]);
        AuditLog::registrar('crear', $imp, "Importó {$entidad}: {$creadas} nuevos, {$act} actualizados, {$err} errores");
        return $imp;
    }
}
