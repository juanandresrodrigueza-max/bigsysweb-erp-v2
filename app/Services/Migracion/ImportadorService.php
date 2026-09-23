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
        'clientes' => ['label' => 'Clientes', 'campos' => ['nombre' => 'Nombre / razón social', 'cuit' => 'CUIT / DNI', 'condicion_iva' => 'Condición IVA', 'email' => 'Email', 'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'localidad' => 'Localidad', 'provincia' => 'Provincia', 'lista' => 'Lista de precios (1-5)', 'limite_credito' => 'Límite de crédito', 'tipo_cliente' => 'Tipo de cliente', 'saldo' => 'Saldo inicial (debe +, a favor −)', 'notas' => 'Notas']],
        'proveedores' => ['label' => 'Proveedores', 'campos' => ['nombre' => 'Nombre / razón social', 'cuit' => 'CUIT', 'condicion_iva' => 'Condición IVA', 'email' => 'Email', 'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'localidad' => 'Localidad', 'provincia' => 'Provincia', 'saldo' => 'Saldo inicial (les debemos +)', 'notas' => 'Notas']],
        'articulos' => ['label' => 'Artículos', 'campos' => ['codigo' => 'Código / SKU', 'barcode' => 'Código de barras', 'descripcion' => 'Descripción', 'rubro' => 'Rubro', 'marca' => 'Marca', 'unidad' => 'Unidad', 'costo' => 'Costo', 'precio1' => 'Precio lista 1', 'precio2' => 'Precio lista 2', 'precio3' => 'Precio lista 3', 'precio4' => 'Precio lista 4', 'precio5' => 'Precio lista 5', 'iva' => 'IVA %', 'stock' => 'Stock inicial', 'stock_min' => 'Stock mínimo', 'proveedor' => 'Proveedor']],
    ];

    public function __construct(private ImportacionPreciosService $lector, private StockService $stock) {}

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
                str_contains($h, 'saldo') || str_contains($h, 'deuda') => 'saldo',
                str_contains($h, 'limite') || str_contains($h, 'límite') => 'limite_credito',
                str_contains($h, 'tipo') && $entidad === 'clientes' => 'tipo_cliente',
                str_contains($h, 'lista') && preg_match('/[2-5]/', $h) => 'precio' . preg_replace('/\D/', '', $h)[0],
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
                    if ($entidad === 'articulos') {
                        $desc = $val($f, 'descripcion'); if ($desc === '') { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': sin descripción'; continue; }
                        $sku = $val($f, 'codigo'); $bc = $val($f, 'barcode');
                        $p = ($sku !== '' ? Product::withTrashed()->where('sku', $sku)->first() : null) ?? ($bc !== '' ? Product::withTrashed()->where('barcode', $bc)->first() : null) ?? Product::withTrashed()->where('name', $desc)->first();
                        $nuevo = ! $p;
                        $p ??= new Product(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'tipo' => 'producto', 'active' => true, 'controla_stock' => true, 'moneda' => 'ARS']);
                        if ($p->trashed()) $p->restore();
                        $rub = $val($f, 'rubro'); $rubId = $rub !== '' ? ($rubros[mb_strtolower($rub)] ?? ($rubros[mb_strtolower($rub)] = Rubro::create(['business_id' => $user->business_id, 'nombre' => $rub])->id)) : $p->rubro_id;
                        $prov = $val($f, 'proveedor'); $provId = $prov !== '' ? ($provs[mb_strtolower($prov)] ?? ($provs[mb_strtolower($prov)] = Contact::create(['business_id' => $user->business_id, 'type' => 'supplier', 'name' => $prov, 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true, 'lista_precios' => 1])->id)) : $p->proveedor_id;
                        $prices = $p->prices ?? [];
                        foreach ([2, 3, 4, 5] as $l) if (($v = $num($val($f, "precio{$l}"))) !== null) $prices[(string) $l] = $v;
                        $p->fill(array_filter([
                            'name' => $desc, 'sku' => $sku !== '' ? $sku : ($p->sku ?: null), 'barcode' => $bc !== '' ? $bc : $p->barcode, 'rubro_id' => $rubId, 'proveedor_id' => $provId, 'marca' => $val($f, 'marca') ?: $p->marca,
                            'unit' => $val($f, 'unidad') ? mb_strtolower(mb_substr($val($f, 'unidad'), 0, 10)) : ($p->unit ?: 'un'), 'cost' => $num($val($f, 'costo')) ?? $p->cost ?? 0, 'price' => $num($val($f, 'precio1')) ?? $p->price ?? 0,
                            'iva' => $num($val($f, 'iva')) ?? $p->iva ?? 21, 'stock_min' => $num($val($f, 'stock_min')) ?? $p->stock_min ?? 0,
                        ], fn($v) => $v !== null) + ['prices' => $prices ?: null]);
                        if (! $p->sku) $p->sku = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($desc)), 0, 6)) . '-' . str_pad((string) (Product::withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT);
                        $p->save();
                        $st = $num($val($f, 'stock'));
                        if ($nuevo && $st !== null && $st > 0 && ($opt['stock_inicial'] ?? true)) $this->stock->entrada($p, $st, 'Stock inicial (importación)', null, null, (float) $p->cost);
                        $nuevo ? $creadas++ : $act++;
                        continue;
                    }
                    // clientes / proveedores
                    $nombre = $val($f, 'nombre'); if ($nombre === '') { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': sin nombre'; continue; }
                    $cuit = preg_replace('/\D/', '', $val($f, 'cuit'));
                    $tipo = $entidad === 'clientes' ? 'customer' : 'supplier';
                    $c = ($cuit !== '' ? Contact::where('type', $tipo)->whereRaw("replace(replace(cuit,'-',''),' ','') = ?", [$cuit])->first() : null) ?? Contact::where('type', $tipo)->where('name', $nombre)->first();
                    $nuevo = ! $c;
                    $c ??= new Contact(['business_id' => $user->business_id, 'type' => $tipo, 'is_active' => true, 'lista_precios' => 1]);
                    $cond = $val($f, 'condicion_iva'); $condN = match (true) { $cond === '' => null, str_contains(mb_strtolower($cond), 'mono') => 'Monotributista', str_contains(mb_strtolower($cond), 'exen') => 'Exento', str_contains(mb_strtolower($cond), 'insc') || str_contains(mb_strtolower($cond), 'ri') => 'Responsable Inscripto', default => 'Consumidor Final' };
                    $tc = $val($f, 'tipo_cliente'); $tcId = $tc !== '' ? ($tipos[mb_strtolower($tc)] ?? null) : null;
                    $c->fill(array_filter([
                        'name' => $nombre, 'cuit' => $cuit !== '' ? (strlen($cuit) === 11 ? substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10) : $cuit) : $c->cuit,
                        'condicion_iva' => $condN ?? $c->condicion_iva ?? ($cuit !== '' && strlen($cuit) === 11 ? 'Responsable Inscripto' : 'Consumidor Final'),
                        'email' => $val($f, 'email') ?: $c->email, 'phone' => $val($f, 'telefono') ?: $c->phone, 'address' => $val($f, 'direccion') ?: $c->address, 'city' => $val($f, 'localidad') ?: $c->city, 'province' => $val($f, 'provincia') ?: $c->province,
                        'lista_precios' => $entidad === 'clientes' ? (int) ($num($val($f, 'lista')) ?: ($c->lista_precios ?: 1)) : 1, 'credit_limit' => $num($val($f, 'limite_credito')) ?? $c->credit_limit ?? 0, 'tipo_cliente_id' => $tcId ?? $c->tipo_cliente_id, 'notes' => $val($f, 'notas') ?: $c->notes,
                    ], fn($v) => $v !== null));
                    $c->save();
                    $saldo = $num($val($f, 'saldo'));
                    if ($nuevo && $saldo !== null && abs($saldo) > 0.005 && ($opt['saldos'] ?? true)) {
                        // Debe positivo = nos debe (cliente) / le debemos (proveedor). Se registra como movimiento de migración en la cuenta corriente.
                        CuentaCorriente::create(['business_id' => $c->business_id, 'contact_id' => $c->id, 'fecha' => $opt['fecha_saldos'] ?? today()->toDateString(), 'tipo' => 'saldo_inicial', 'concepto' => 'Saldo inicial (migración)', 'debe' => $saldo > 0 ? $saldo : 0, 'haber' => $saldo < 0 ? -$saldo : 0]);
                        CuentaCorriente::recalcularSaldo($c->id);
                    }
                    $nuevo ? $creadas++ : $act++;
                } catch (\Throwable $e) { $err++; $detalle[] = 'Fila ' . ($i + 2) . ': ' . $e->getMessage(); }
            }
        });
        $imp = Importacion::create(['business_id' => $user->business_id, 'user_id' => $user->id, 'entidad' => $entidad, 'archivo' => $opt['archivo'] ?? null, 'leidas' => $leidas, 'creadas' => $creadas, 'actualizadas' => $act, 'errores' => $err, 'detalle' => array_slice($detalle, 0, 100)]);
        AuditLog::registrar('crear', $imp, "Importó {$entidad}: {$creadas} nuevos, {$act} actualizados, {$err} errores");
        return $imp;
    }
}
