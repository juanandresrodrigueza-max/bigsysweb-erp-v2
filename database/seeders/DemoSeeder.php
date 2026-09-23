<?php

namespace Database\Seeders;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PuntoVenta;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\TipoCliente;
use App\Models\User;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Services\Comprobantes\CobroService;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoService;
use App\Services\Fondos\FondosService;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Empresa demo (corralón) con dos sucursales, un usuario por rol y un mes de facturas, cobros y acopios reales.
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Business::where('slug', 'demo')->exists()) {
            return;
        }

        DB::transaction(function () {
            $dueno = User::create(['name' => 'Juan Rodríguez', 'email' => 'demo@bigsys.com.ar', 'password' => 'password', 'status' => 'active']);

            $empresa = Business::create([
                'name' => 'Corralón Demo', 'slug' => 'demo', 'email' => 'demo@bigsys.com.ar', 'phone' => '351 555-0100',
                'cuit' => '30-71234567-8', 'razon_social' => 'Corralón Demo S.R.L.', 'condicion_iva' => 'Responsable Inscripto',
                'afip_punto_venta' => '0001', 'owner_id' => $dueno->id,
            ]);

            $central = BusinessLocation::create(['business_id' => $empresa->id, 'name' => 'Casa Central', 'short_name' => 'CC', 'city' => 'Córdoba', 'province' => 'Córdoba', 'address' => 'Av. Colón 1234', 'is_default' => true]);
            $norte   = BusinessLocation::create(['business_id' => $empresa->id, 'name' => 'Sucursal Norte', 'short_name' => 'NOR', 'city' => 'Villa Allende', 'province' => 'Córdoba', 'address' => 'Ruta E-53 km 4']);

            $pro = Plan::where('slug', 'pro')->first() ?? Plan::first();
            Subscription::create(['business_id' => $empresa->id, 'plan_id' => $pro->id, 'status' => 'active', 'amount' => $pro->price_monthly, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(23)]);

            Role::crearRolesSistema($empresa->id);
            $roles = $empresa->roles()->pluck('id', 'slug');

            $dueno->forceFill(['business_id' => $empresa->id, 'role_id' => $roles['dueno'], 'current_location_id' => $central->id])->save();
            $dueno->locations()->sync([$central->id => ['role_id' => $roles['dueno']], $norte->id => ['role_id' => $roles['dueno']]]);

            foreach ([
                ['Ana Admin', 'admin@bigsys.com.ar', 'administrador', [$central, $norte]],
                ['Carla Contable', 'contador@bigsys.com.ar', 'contador', [$central]],
                ['Vito Vendedor', 'vendedor@bigsys.com.ar', 'vendedor', [$norte]],
                ['Caro Cajera', 'cajero@bigsys.com.ar', 'cajero', [$central]],
                ['Dario Depósito', 'deposito@bigsys.com.ar', 'deposito', [$central, $norte]],
            ] as [$n, $e, $rol, $sucs]) {
                $u = User::create(['business_id' => $empresa->id, 'name' => $n, 'email' => $e, 'password' => 'password', 'status' => 'active', 'role_id' => $roles[$rol], 'current_location_id' => $sucs[0]->id]);
                $u->locations()->sync(collect($sucs)->mapWithKeys(fn($s) => [$s->id => ['role_id' => $roles[$rol]]])->all());
            }

            PuntoVenta::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'numero' => 1, 'modo' => 'electronico']);
            PuntoVenta::create(['business_id' => $empresa->id, 'business_location_id' => $norte->id, 'numero' => 2, 'modo' => 'electronico']);

            $tipos = collect([
                ['Mayorista', 2, 30, 5, 2000000, '#4f3089'], ['Minorista', 1, 0, 0, 0, '#e4003f'], ['Obra', 3, 15, 8, 1000000, '#a42785'], ['Revendedor', 4, 7, 10, 500000, '#1f9d5b'],
            ])->mapWithKeys(fn($t) => [$t[0] => TipoCliente::create(['business_id' => $empresa->id, 'nombre' => $t[0], 'lista_precios' => $t[1], 'dias_pago' => $t[2], 'descuento' => $t[3], 'limite_credito' => $t[4], 'color' => $t[5]])]);

            $rubros = collect(['Áridos' => '#c77d00', 'Cementos y cales' => '#6f6a62', 'Hierros' => '#4f3089', 'Ladrillos' => '#e4003f', 'Elaborados' => '#1f9d5b'])
                ->mapWithKeys(fn($col, $n) => [$n => \App\Models\Rubro::create(['business_id' => $empresa->id, 'nombre' => $n, 'color' => $col])]);

            // Categorías anidadas de ejemplo (tres niveles): Hierros › Hierro aletado › Diámetros chicos
            $subH = \App\Models\Rubro::create(['business_id' => $empresa->id, 'parent_id' => $rubros['Hierros']->id, 'nombre' => 'Hierro aletado', 'color' => '#4f3089']);
            $subH2 = \App\Models\Rubro::create(['business_id' => $empresa->id, 'parent_id' => $subH->id, 'nombre' => 'Diámetros chicos (6 a 10 mm)', 'color' => '#4f3089']);
            \App\Models\Rubro::create(['business_id' => $empresa->id, 'parent_id' => $rubros['Cementos y cales']->id, 'nombre' => 'Cementos', 'color' => '#6f6a62']);
            // [nombre, sku, precio, costo, stock inicial casa central, stock inicial norte, mínimo, unidad, rubro, tipo]
            $productos = collect([
                ['Cemento x 50 kg', 'CEM50', 9800, 7200, 1500, 400, 200, 'un', 'Cementos y cales', 'producto'], ['Hierro 8 mm x 12 m', 'HIE08', 6500, 4900, 300, 80, 60, 'un', 'Hierros', 'producto'],
                ['Arena fina m³', 'ARE01', 28000, 21000, 220, 40, 20, 'm3', 'Áridos', 'producto'], ['Ladrillo hueco 12x18x33', 'LAD12', 520, 380, 12000, 3000, 2000, 'un', 'Ladrillos', 'producto'],
                ['Cal hidratada x 25 kg', 'CAL25', 4100, 3000, 150, 30, 40, 'un', 'Cementos y cales', 'producto'], ['Piedra partida m³', 'PIE01', 32000, 24500, 130, 20, 15, 'm3', 'Áridos', 'producto'],
                ['Hierro 10 mm x 12 m', 'HIE10', 9900, 7600, 420, 80, 60, 'un', 'Hierros', 'producto'], ['Malla sima 15x15 6mm', 'MAL15', 38000, 29000, 220, 40, 30, 'un', 'Hierros', 'producto'],
                ['Bolsa vacía 30 kg impresa', 'BOL30', 0, 120, 600, 0, 200, 'un', 'Elaborados', 'insumo'], ['Premezcla revoque grueso x 30 kg', 'PRE30', 5200, 0, 0, 0, 50, 'bolsa', 'Elaborados', 'elaborado'],
            ])->map(fn($p) => Product::create([
                'business_id' => $empresa->id, 'business_location_id' => $central->id, 'rubro_id' => $rubros[$p[8]]->id, 'tipo' => $p[9], 'name' => $p[0], 'sku' => $p[1],
                'price' => $p[2], 'prices' => $p[2] ? ['2' => round($p[2] * 0.93), '3' => round($p[2] * 0.9), '4' => round($p[2] * 0.88), '5' => round($p[2] * 0.85)] : null,
                'cost' => $p[3], 'iva' => 21, 'stock' => 0, 'stock_min' => $p[6], 'unit' => $p[7], 'active' => true, 'precio_actualizado_en' => now()->subDays(20),
            ]));
            foreach ([['CEM50', '7790001000011', true], ['HIE08', '7790001000028', true], ['CAL25', '7790001000035', true], ['LAD12', '7790001000042', true], ['ARE01', null, true], ['MAL15', '7790001000059', false]] as [$sku, $bc, $fav]) {
                $productos->firstWhere('sku', $sku)->forceFill(['barcode' => $bc, 'favorito_pos' => $fav])->save();
            }
            // Hierros chicos en la subcategoría de tercer nivel, con lista 6 y dos escalas de descuento por cantidad
            foreach (['HIE08', 'HIE10'] as $sku) { $p = $productos->firstWhere('sku', $sku); $p->forceFill(['rubro_id' => $subH2->id, 'prices' => ($p->prices ?? []) + ['6' => round($p->price * 0.82)], 'desc_cant_min' => 10, 'desc_cant_pct' => 5, 'desc_cant2_min' => 50, 'desc_cant2_pct' => 12])->save(); }
            $stockInicial = collect([
                ['CEM50', 1500, 400], ['HIE08', 300, 80], ['ARE01', 220, 40], ['LAD12', 12000, 3000], ['CAL25', 150, 30], ['PIE01', 130, 20], ['HIE10', 420, 80], ['MAL15', 220, 40], ['BOL30', 600, 0],
            ]);

            $clientes = collect([
                ['Constructora Del Valle S.A.', '30-70012345-6', 'Responsable Inscripto', 'Mayorista', 'Av. Vélez Sarsfield 2200', 'Córdoba'],
                ['Marcelo Giménez (obra Nueva Córdoba)', '20-28765432-1', 'Monotributista', 'Obra', 'Obispo Trejo 850', 'Córdoba'],
                ['Ferretería El Tornillo', '30-65432109-8', 'Responsable Inscripto', 'Revendedor', 'Ruta 9 km 12', 'Juárez Celman'],
                ['Consumidor Final', null, 'Consumidor Final', 'Minorista', null, null],
                ['Estudio Arq. Pereyra', '27-30111222-3', 'Responsable Inscripto', 'Obra', 'Chacabuco 120', 'Córdoba'],
                ['Lucía Fernández', '27-33444555-6', 'Consumidor Final', 'Minorista', 'Los Nogales 45', 'Villa Allende'],
            ])->map(fn($c) => Contact::create([
                'business_id' => $empresa->id, 'type' => 'customer', 'name' => $c[0], 'cuit' => $c[1], 'condicion_iva' => $c[2], 'tipo_cliente_id' => $tipos[$c[3]]->id,
                'lista_precios' => $tipos[$c[3]]->lista_precios, 'dias_pago' => $tipos[$c[3]]->dias_pago, 'descuento' => $tipos[$c[3]]->descuento, 'credit_limit' => $tipos[$c[3]]->limite_credito,
                'address' => $c[4], 'city' => $c[5], 'is_active' => true, 'email' => strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $c[0])[0])) . '@cliente.com.ar',
            ]));

            $loma = Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Loma Negra S.A.', 'cuit' => '30-50000000-1', 'condicion_iva' => 'Responsable Inscripto', 'dias_pago' => 30, 'is_active' => true]);
            $acindar = Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Acindar Distribuidora', 'cuit' => '30-50000000-2', 'condicion_iva' => 'Responsable Inscripto', 'dias_pago' => 15, 'is_active' => true]);
            $arenera = Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Arenera del Suquía', 'cuit' => '20-22333444-5', 'condicion_iva' => 'Monotributista', 'dias_pago' => 0, 'is_active' => true]);

            // Cuentas de fondos (antes de facturar, para que los cobros impacten)
            $cajaCentral = CuentaFondos::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'tipo' => 'caja', 'nombre' => 'Caja Casa Central', 'es_default' => true, 'saldo_minimo' => 50000]);
            $cajaNorte   = CuentaFondos::create(['business_id' => $empresa->id, 'business_location_id' => $norte->id, 'tipo' => 'caja', 'nombre' => 'Caja Sucursal Norte']);
            $banco       = CuentaFondos::create(['business_id' => $empresa->id, 'tipo' => 'banco', 'nombre' => 'Banco Galicia CC', 'banco' => 'Galicia', 'cbu' => '0070000000000000000001', 'alias' => 'corralon.demo', 'es_default' => true, 'saldo_minimo' => 500000]);
            $mp          = CuentaFondos::create(['business_id' => $empresa->id, 'tipo' => 'billetera', 'nombre' => 'MercadoPago', 'es_default' => true]);
            foreach (['Sueldos' => '#4f3089', 'Alquiler' => '#a42785', 'Servicios' => '#e4003f', 'Fletes' => '#1f9d5b', 'Impuestos' => '#c77d00'] as $n => $col) {
                ExpenseCategory::create(['business_id' => $empresa->id, 'name' => $n, 'color' => $col]);
            }

            \App\Services\Contabilidad\PlanCuentas::crear($empresa);

            // Un mes de facturas, algunas cobradas, dos presupuestos y un acopio, pasando por el servicio real.
            Auth::login($dueno);
            $comprobantes = app(ComprobanteService::class);
            $cobros = app(CobroService::class);
            $compras = app(CompraService::class);
            $pagos = app(PagoService::class);
            $fondos = app(FondosService::class);
            $stock = app(\App\Services\Stock\StockService::class);
            mt_srand(7);
            $vendibles = $productos->filter(fn($p) => (float) $p->price > 0 && $p->tipo === 'producto')->values();

            // Stock inicial por depósito (Casa Central y Norte)
            $depCentral = \App\Models\Deposito::porDefecto($central->id);
            $depNorte = \App\Models\Deposito::porDefecto($norte->id);
            foreach ($stockInicial as [$sku, $cc, $no]) {
                $p = $productos->firstWhere('sku', $sku);
                if ($cc) $stock->entrada($p, $cc, 'Stock inicial', null, $depCentral, (float) $p->cost);
                if ($no) $stock->entrada($p, $no, 'Stock inicial', null, $depNorte, (float) $p->cost);
            }

            $fondos->registrar($banco, ['fecha' => today()->subDays(35)->toDateString(), 'origen' => 'ajuste', 'concepto' => 'Saldo inicial', 'ingreso' => 3200000]);
            $fondos->registrar($cajaCentral, ['fecha' => today()->subDays(35)->toDateString(), 'origen' => 'ajuste', 'concepto' => 'Saldo inicial', 'ingreso' => 180000]);
            $fondos->registrar($cajaNorte, ['fecha' => today()->subDays(35)->toDateString(), 'origen' => 'ajuste', 'concepto' => 'Saldo inicial', 'ingreso' => 60000]);

            // Compras del mes: Loma Negra (cemento y cal), Acindar (hierros), arenera (contado)
            $compraDe = function ($prov, $dias, $tipo, $num, $items, $registrar = true) use ($compras, $empresa) {
                $c = $compras->guardarBorrador(['contact_id' => $prov->id, 'tipo' => $tipo, 'numero_proveedor' => $num, 'fecha' => today()->subDays($dias)->toDateString(), 'condicion' => $prov->dias_pago ? 'cta_cte' : 'contado', 'origen_carga' => 'manual', 'items' => $items]);
                return $registrar ? $compras->registrar($c) : $c;
            };
            $c1 = $compraDe($loma, 40, 'FA', '0012-00045871', [['product_id' => $productos[0]->id, 'descripcion' => $productos[0]->name, 'cantidad' => 600, 'precio_unit' => 7100, 'alicuota_iva' => 21], ['product_id' => $productos[4]->id, 'descripcion' => $productos[4]->name, 'cantidad' => 80, 'precio_unit' => 2950, 'alicuota_iva' => 21]]);
            $c2 = $compraDe($loma, 12, 'FA', '0012-00046120', [['product_id' => $productos[0]->id, 'descripcion' => $productos[0]->name, 'cantidad' => 400, 'precio_unit' => 7250, 'alicuota_iva' => 21]]);
            $c3 = $compraDe($acindar, 20, 'FA', '0003-00009915', [['product_id' => $productos[1]->id, 'descripcion' => $productos[1]->name, 'cantidad' => 150, 'precio_unit' => 4850, 'alicuota_iva' => 21], ['product_id' => $productos[6]->id, 'descripcion' => $productos[6]->name, 'cantidad' => 120, 'precio_unit' => 7500, 'alicuota_iva' => 21], ['product_id' => $productos[7]->id, 'descripcion' => $productos[7]->name, 'cantidad' => 60, 'precio_unit' => 28800, 'alicuota_iva' => 21]]);
            $c4 = $compraDe($arenera, 6, 'FC', '0001-00000318', [['product_id' => $productos[2]->id, 'descripcion' => $productos[2]->name, 'cantidad' => 40, 'precio_unit' => 20500, 'alicuota_iva' => 0], ['product_id' => $productos[5]->id, 'descripcion' => $productos[5]->name, 'cantidad' => 10, 'precio_unit' => 24000, 'alicuota_iva' => 0]]);
            $compraDe($acindar, 1, 'FA', '0003-00010102', [['product_id' => $productos[1]->id, 'descripcion' => $productos[1]->name, 'cantidad' => 100, 'precio_unit' => 4900, 'alicuota_iva' => 21]], false);

            // Pagos: la primera de Loma con transferencia y cheque propio; arenera al contado en efectivo; Acindar parcial con retención
            $pagos->registrar($loma, ['fecha' => today()->subDays(9)->toDateString(), 'medios' => [['medio' => 'transferencia', 'monto' => 3000000, 'cuenta_fondos_id' => $banco->id, 'referencia' => 'TRF 55120'], ['medio' => 'cheque_propio', 'monto' => (float) $c1->total - 3000000, 'cuenta_fondos_id' => $banco->id, 'datos' => ['numero' => '00458812', 'fecha_pago' => today()->addDays(12)->toDateString()]]], 'imputaciones' => [['comprobante_id' => $c1->id, 'monto' => (float) $c1->total]]]);
            $pagos->registrar($arenera, ['fecha' => today()->subDays(6)->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => (float) $c4->total, 'cuenta_fondos_id' => $cajaCentral->id]], 'imputaciones' => [['comprobante_id' => $c4->id, 'monto' => (float) $c4->total]]]);
            $pagos->registrar($acindar, ['fecha' => today()->subDays(3)->toDateString(), 'medios' => [['medio' => 'transferencia', 'monto' => 1500000, 'cuenta_fondos_id' => $banco->id], ['medio' => 'retencion', 'monto' => 42000, 'datos' => ['tipo' => 'ganancias', 'base' => 2100000, 'alicuota' => 2, 'certificado' => 'RG-2026-0001']]], 'imputaciones' => [['comprobante_id' => $c3->id, 'monto' => 1542000]]]);

            // Gastos varios
            $cats = ExpenseCategory::pluck('id', 'name');
            $fondos->registrar($banco, ['fecha' => today()->subDays(15)->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $cats['Alquiler'], 'concepto' => 'Alquiler galpón septiembre', 'egreso' => 480000]);
            $fondos->registrar($cajaCentral, ['fecha' => today()->subDays(4)->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $cats['Fletes'], 'concepto' => 'Flete reparto zona norte', 'egreso' => 35000]);
            $fondos->registrar($banco, ['fecha' => today()->subDays(2)->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $cats['Servicios'], 'concepto' => 'EPEC + internet', 'egreso' => 92000]);

            $facturas = [];
            foreach (range(0, 29) as $i) {
                $fecha = today()->subDays(29 - $i);
                foreach (range(1, mt_rand(1, 3)) as $n) {
                    $suc = mt_rand(0, 3) ? $central : $norte;
                    $dueno->forceFill(['current_location_id' => $suc->id])->save();
                    $cliente = $clientes->random();
                    $items = $vendibles->random(mt_rand(1, 3))->map(fn($p) => ['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => mt_rand(1, 12), 'precio_unit' => $p->precioLista($cliente->lista_precios), 'descuento' => $cliente->descuento, 'alicuota_iva' => 21])->values()->all();
                    $condicion = $cliente->dias_pago > 0 && mt_rand(0, 2) ? 'cta_cte' : 'contado';
                    $f = $comprobantes->guardarBorrador(['contact_id' => $cliente->id, 'tipo' => 'FX', 'fecha' => $fecha->toDateString(), 'condicion' => $condicion, 'items' => $items]);
                    $cliente->refresh();
                    if ($condicion === 'cta_cte' && (float) $cliente->credit_limit > 0 && (float) $cliente->balance + (float) $f->total > (float) $cliente->credit_limit) {
                        $condicion = 'contado';
                        $f = $comprobantes->guardarBorrador(['contact_id' => $cliente->id, 'tipo' => 'FX', 'fecha' => $fecha->toDateString(), 'condicion' => 'contado', 'items' => $items], $f);
                    }
                    $f = $comprobantes->emitir($f);
                    $f->forceFill(['created_at' => $fecha->setTime(mt_rand(9, 18), mt_rand(0, 59)), 'emitido_en' => $fecha])->save();
                    if ($condicion === 'contado' || mt_rand(0, 3) === 0) {
                        $cobros->registrar($cliente, ['fecha' => $fecha->toDateString(), 'medios' => [['medio' => mt_rand(0, 1) ? 'efectivo' : 'transferencia', 'monto' => (float) $f->total]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => (float) $f->total]]]);
                    }
                    $facturas[] = $f;
                }
            }
            $dueno->forceFill(['current_location_id' => $central->id])->save();

            // Factura vencida hace 40 días para que haya mora
            $delValle = $clientes[0];
            $delValle->update(['credit_limit' => 5000000]);
            $vieja = $comprobantes->guardarBorrador(['contact_id' => $delValle->id, 'tipo' => 'FX', 'fecha' => today()->subDays(70)->toDateString(), 'condicion' => 'cta_cte', 'items' => [['product_id' => $productos[0]->id, 'descripcion' => $productos[0]->name, 'cantidad' => 120, 'precio_unit' => $productos[0]->precioLista(2), 'alicuota_iva' => 21]]]);
            $comprobantes->emitir($vieja);

            // Acopio: obra paga 200 bolsas de cemento y retira de a poco
            $obra = $clientes[1];
            $acopio = $comprobantes->guardarBorrador(['contact_id' => $obra->id, 'tipo' => 'FX', 'fecha' => today()->subDays(12)->toDateString(), 'condicion' => 'contado', 'es_acopio' => true, 'items' => [
                ['product_id' => $productos[0]->id, 'descripcion' => $productos[0]->name, 'cantidad' => 200, 'precio_unit' => $productos[0]->precioLista(3), 'alicuota_iva' => 21],
                ['product_id' => $productos[3]->id, 'descripcion' => $productos[3]->name, 'cantidad' => 3000, 'precio_unit' => $productos[3]->precioLista(3), 'alicuota_iva' => 21],
            ]]);
            $acopio = $comprobantes->emitir($acopio);
            $cobros->registrar($obra, ['fecha' => today()->subDays(12)->toDateString(), 'medios' => [['medio' => 'transferencia', 'monto' => round((float) $acopio->total - 900000, 2), 'referencia' => 'TRF 88213'], ['medio' => 'cheque', 'monto' => 500000, 'datos' => ['numero' => '11223344', 'banco' => 'Banco Nación', 'fecha_pago' => today()->addDays(10)->toDateString()]], ['medio' => 'cheque', 'monto' => 400000, 'datos' => ['numero' => '11223345', 'banco' => 'Banco Nación', 'fecha_pago' => today()->addDays(40)->toDateString()]]], 'imputaciones' => [['comprobante_id' => $acopio->id, 'monto' => (float) $acopio->total]]]);
            $fondos->abrirTurno($cajaCentral, (float) $cajaCentral->fresh()->saldo);
            app(\App\Services\Comprobantes\AcopioService::class)->retirar($acopio->fresh()->acopio, ['fecha' => today()->subDays(8)->toDateString(), 'retirado_por' => 'Camión propio', 'items' => [['acopio_item_id' => $acopio->acopio->items[0]->id, 'cantidad' => 60], ['acopio_item_id' => $acopio->acopio->items[1]->id, 'cantidad' => 1000]]]);

            // Presupuestos: uno reciente y uno viejo sin respuesta
            foreach ([[2, $clientes[4]], [12, $clientes[2]]] as [$dias, $cli]) {
                $p = $comprobantes->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'PRE', 'fecha' => today()->subDays($dias)->toDateString(), 'condicion' => 'cta_cte', 'items' => $vendibles->random(3)->map(fn($x) => ['product_id' => $x->id, 'descripcion' => $x->name, 'cantidad' => mt_rand(5, 40), 'precio_unit' => $x->precioLista($cli->lista_precios), 'alicuota_iva' => 21])->values()->all()]);
                $comprobantes->emitir($p);
            }

            // Producción: fórmula de premezcla y dos órdenes (una terminada, otra pendiente)
            $produccion = app(\App\Services\Produccion\ProduccionService::class);
            $formula = $produccion->guardarFormula(['product_id' => $productos->firstWhere('sku', 'PRE30')->id, 'name' => 'Premezcla revoque grueso (tanda 20 bolsas)', 'yield_quantity' => 20, 'yield_unit' => 'bolsa', 'tiempo_minutos' => 90,
                'instructions' => "1. Cargar arena y cal en la mezcladora.\n2. Agregar cemento y mezclar 10 minutos en seco.\n3. Embolsar en bolsas de 30 kg y sellar.",
                'items' => [['product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'quantity' => 4, 'unit' => 'un'], ['product_id' => $productos->firstWhere('sku', 'CAL25')->id, 'quantity' => 2, 'unit' => 'un'], ['product_id' => $productos->firstWhere('sku', 'ARE01')->id, 'quantity' => 0.5, 'unit' => 'm3'], ['product_id' => $productos->firstWhere('sku', 'BOL30')->id, 'quantity' => 20, 'unit' => 'un']]]);
            $op1 = $produccion->crearOrden(['recipe_id' => $formula->id, 'quantity' => 60, 'deposito_id' => $depCentral->id, 'scheduled_at' => today()->subDays(6)->toDateTimeString(), 'notes' => 'Reposición semanal']);
            $produccion->terminar($op1, 60);
            $op2 = $produccion->crearOrden(['recipe_id' => $formula->id, 'quantity' => 40, 'deposito_id' => $depCentral->id, 'scheduled_at' => today()->addDay()->toDateTimeString(), 'notes' => 'Para pedido de Del Valle']);
            $produccion->iniciar($op2);

            // Transferencia entre depósitos e inventario reciente
            $stock->transferir($depCentral, $depNorte, [['product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'cantidad' => 100], ['product_id' => $productos->firstWhere('sku', 'HIE08')->id, 'cantidad' => 20]], today()->subDays(3)->toDateString(), 'Reposición Norte');
            $stock->cerrarInventario($depNorte, [$productos->firstWhere('sku', 'LAD12')->id => 1985, $productos->firstWhere('sku', 'CAL25')->id => 20, $productos->firstWhere('sku', 'PIE01')->id => 11.5], today()->subDays(2)->toDateString(), 'Conteo mensual');

            // Fase 7: vendedores con comisión, cadena de precios en algunos artículos, dólar y una orden de compra abierta
            $vito = \App\Models\Vendedor::create(['business_id' => $empresa->id, 'user_id' => User::where('email', 'vendedor@bigsys.com.ar')->value('id'), 'nombre' => 'Vito Vendedor', 'comision_venta' => 2, 'comision_cobro' => 1, 'activo' => true]);
            $marta = \App\Models\Vendedor::create(['business_id' => $empresa->id, 'nombre' => 'Marta Mostrador', 'comision_venta' => 1.5, 'comision_cobro' => 0, 'activo' => true]);
            Contact::customers()->where('business_id', $empresa->id)->orderBy('id')->limit(3)->update(['vendedor_id' => $vito->id, 'interes_mora' => 4]);
            \App\Models\Comprobante::withoutGlobalScopes()->where('business_id', $empresa->id)->where('direccion', 'venta')->whereIn('contact_id', Contact::customers()->where('vendedor_id', $vito->id)->pluck('id'))->update(['vendedor_id' => $vito->id]);
            \App\Models\Comprobante::withoutGlobalScopes()->where('business_id', $empresa->id)->where('direccion', 'venta')->whereNull('vendedor_id')->whereRaw('id % 2 = 0')->update(['vendedor_id' => $marta->id]);
            foreach (['CEM50' => [42000, 5, ['1' => 35, '2' => 28, '3' => 40]], 'HIE08' => [9800, 0, ['1' => 30, '2' => 25]]] as $sku => [$pc, $dto, $m]) {
                $p = $productos->firstWhere('sku', $sku); $p->fill(['precio_compra' => $pc, 'descuento_proveedor' => $dto, 'margenes' => $m, 'desc_cant_min' => 10, 'desc_cant_pct' => 5, 'desc_cant2_min' => 50, 'desc_cant2_pct' => 12]); $p->recalcularDesdeCosto(); $p->save();
            }
            \App\Models\Cotizacion::updateOrCreate(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'oficial'], ['compra' => 1420, 'venta' => 1460, 'fuente' => 'manual']);
            $ocs = app(\App\Services\Compras\OrdenCompraService::class);
            $oc = $ocs->guardar(['contact_id' => $loma->id, 'fecha' => today()->subDays(4)->toDateString(), 'fecha_entrega' => today()->subDay()->toDateString(), 'notas' => 'Entregar en Central, turno mañana', 'origen' => 'sugerido', 'items' => [['product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'cantidad' => 200, 'precio_unit' => 42000], ['product_id' => $productos->firstWhere('sku', 'CAL25')->id, 'cantidad' => 80, 'precio_unit' => 6500]]]);
            $ocs->enviar($oc);
            $ocs->guardar(['contact_id' => $acindar->id, 'fecha' => today()->toDateString(), 'fecha_entrega' => today()->addDays(5)->toDateString(), 'origen' => 'manual', 'items' => [['product_id' => $productos->firstWhere('sku', 'HIE08')->id, 'cantidad' => 300, 'precio_unit' => 9800]]]);

            // Fase 8: abono recurrente, avisos de cobranza configurados, una factura con entrega pendiente
            $abonos = app(\App\Services\Ventas\AbonosService::class);
            $ab = $abonos->guardar(['contact_id' => Contact::customers()->where('credit_limit', 0)->where('name', '!=', 'Consumidor Final')->orderBy('id')->value('id') ?? Contact::customers()->first()->id, 'descripcion' => 'Abono mantenimiento obra', 'items' => [['descripcion' => 'Servicio de mantenimiento · cuota {cuota} · {periodo}', 'cantidad' => 1, 'precio_unit' => 85000, 'alicuota_iva' => 21]], 'condicion' => 'cta_cte', 'frecuencia' => 'mensual', 'dia_emision' => 5, 'desde' => today()->subMonths(2)->startOfMonth()->toDateString(), 'meses_excluidos' => [1], 'emitir_auto' => true]);
            $abonos->emitir($ab, true); $abonos->emitir($ab, true);
            $empresa->update(['recordatorios' => ['activo' => true, 'dias' => [-3, 0, 7, 30], 'canales' => ['mail', 'whatsapp'], 'texto' => \App\Services\Ventas\CobranzasService::DEFAULT['texto']]]);
            $fep = $comprobantes->guardarBorrador(['contact_id' => Contact::customers()->where('credit_limit', 0)->where('name', '!=', 'Consumidor Final')->orderByDesc('id')->value('id') ?? Contact::customers()->first()->id, 'tipo' => 'FX', 'fecha' => today()->subDay()->toDateString(), 'condicion' => 'cta_cte', 'entrega_pendiente' => true, 'notas' => 'Entregar en obra en dos viajes', 'items' => [['product_id' => $productos->firstWhere('sku', 'LAD12')->id, 'cantidad' => 3000, 'precio_unit' => $productos->firstWhere('sku', 'LAD12')->price, 'descuento' => 0], ['product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'cantidad' => 60, 'precio_unit' => $productos->firstWhere('sku', 'CEM50')->price, 'descuento' => 0]]]);
            $fep = $comprobantes->emitir($fep);
            app(\App\Services\Ventas\EntregasService::class)->convertirParcial($fep, 'REM', [$fep->items[0]->id => 1500]);

            // Fase 9: percepciones activas, CBU para FCE, padrón IIBB con los clientes, IPC de los últimos meses, un perecedero con partidas y un precio modificado al facturar
            $empresa->update(['cbu_fce' => '0170099220000012345678', 'cierre_ejercicio_mes' => 12, 'impuestos' => ['percepcion_iibb' => ['activo' => true, 'jurisdiccion' => 'ARBA', 'alicuota' => 3, 'minimo' => 0, 'solo_padron' => false], 'agente_percepcion' => true, 'agente_retencion' => true]]);
            foreach (Contact::where('business_id', $empresa->id)->whereNotNull('cuit')->get() as $i => $c) {
                $cuit = preg_replace('/\D/', '', $c->cuit);
                if (strlen($cuit) === 11) \App\Models\PadronIibb::updateOrCreate(['jurisdiccion' => 'ARBA', 'cuit' => $cuit], ['alic_percepcion' => [1.5, 2, 3, 4][$i % 4], 'alic_retencion' => [1, 2, 3][$i % 3], 'desde' => today()->startOfMonth(), 'hasta' => today()->endOfMonth(), 'fuente' => 'demo']);
            }
            $ipc = 6500;
            for ($m = 14; $m >= 0; $m--) { $ipc = round($ipc * 1.03, 4); \App\Models\IndiceIpc::updateOrCreate(['periodo' => today()->subMonths($m)->format('Y-m')], ['valor' => $ipc, 'fuente' => 'demo']); }
            $yogur = Product::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'rubro_id' => $rubros->first()->id, 'name' => 'Sellador acrílico 300 ml', 'sku' => 'SEL300', 'tipo' => 'producto', 'unit' => 'un', 'cost' => 2100, 'price' => 3500, 'iva' => 21, 'stock' => 0, 'stock_min' => 20, 'active' => true, 'controla_stock' => true, 'perecedero' => true, 'proveedor_id' => $loma->id, 'barcode' => '7790001234567']);
            $lotesSvc = app(\App\Services\Stock\LotesService::class);
            $stockSvc = app(\App\Services\Stock\StockService::class);
            $stockSvc->entrada($yogur, 40, 'Compra inicial lote A', null, null, 2100, $central->id, ['lote' => 'A-2311', 'vencimiento' => today()->addDays(12)->toDateString()]);
            $stockSvc->entrada($yogur, 60, 'Compra lote B', null, null, 2100, $central->id, ['lote' => 'B-2402', 'vencimiento' => today()->addMonths(8)->toDateString()]);
            $stockSvc->entrada($yogur, 15, 'Remanente vencido', null, null, 2100, $central->id, ['lote' => 'Z-2205', 'vencimiento' => today()->subDays(5)->toDateString()]);
            $fnov = $comprobantes->guardarBorrador(['contact_id' => Contact::customers()->where('business_id', $empresa->id)->where('credit_limit', 0)->where('name', '!=', 'Consumidor Final')->orderBy('id')->value('id') ?? Contact::customers()->first()->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => [['product_id' => $yogur->id, 'cantidad' => 10, 'precio_unit' => 3100, 'descuento' => 0], ['product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'cantidad' => 5, 'precio_unit' => $productos->firstWhere('sku', 'CEM50')->price, 'descuento' => 0]]]);
            $comprobantes->emitir($fnov);

            // Fase 11: tienda propia activa, menú y reservas, programa de puntos, un canal WooCommerce, pedidos web y una reserva
            $empresa->update([
                'tienda' => ['activa' => true, 'slug' => 'corralon-demo', 'nombre' => 'Corralón Demo', 'descripcion' => 'Materiales de construcción con entrega en el día en Córdoba', 'whatsapp' => '5493515550100', 'retiro' => true, 'envio' => true, 'costo_envio' => 8000, 'envio_gratis_desde' => 300000, 'zona_envio' => 'Córdoba capital y Villa Allende', 'pagos' => ['link' => true, 'transferencia' => true, 'efectivo' => true], 'alias' => 'corralon.demo', 'lista_precios' => 1, 'iva_incluido' => true, 'minimo_pedido' => 10000, 'mostrar_stock' => true, 'menu_activo' => true, 'reservas_activas' => true, 'reservas_horario' => '12:00-15:00, 20:00-00:00', 'reservas_capacidad' => 40],
                'fidelizacion' => ['activo' => true, 'pesos_por_punto' => 1000, 'valor_punto' => 10, 'minimo_canje' => 50, 'bienvenida' => 0, 'excluir_cf' => true],
            ]);
            $canal = \App\Models\Canal::create(['business_id' => $empresa->id, 'tipo' => 'woocommerce', 'nombre' => 'Tienda Woo (demo)', 'credenciales' => ['url' => 'https://demo.corralon.com.ar', 'consumer_key' => 'ck_demo', 'consumer_secret' => 'cs_demo'], 'activo' => true, 'sync_stock' => false, 'importar_pedidos' => true]);
            $tiendaSvc = app(\App\Services\Canales\TiendaService::class);
            $tiendaSvc->crearPedido($empresa, ['items' => [['product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'cantidad' => 20], ['product_id' => $productos->firstWhere('sku', 'HIE08')->id, 'cantidad' => 10]], 'cliente' => ['nombre' => 'Rodrigo Paz', 'telefono' => '3515557788', 'email' => 'rodrigo@paz.com', 'direccion' => 'Av. Colón 2500, Córdoba', 'notas' => 'Dejar en portería'], 'entrega' => 'envio', 'pago' => 'link'], 'tienda');
            $pw2 = $tiendaSvc->crearPedido($empresa, ['items' => [['product_id' => $productos->firstWhere('sku', 'CAL25')->id, 'cantidad' => 5]], 'cliente' => ['nombre' => 'Lucía Fernández', 'telefono' => '3515552222'], 'entrega' => 'retiro', 'pago' => 'efectivo'], 'tienda', Contact::customers()->where('name', 'Lucía Fernández')->first());
            app(\App\Services\Canales\CanalesService::class)->alta($empresa, $canal, ['external_id' => 'WC-1041', 'cliente' => ['nombre' => 'Martín Sosa', 'email' => 'msosa@mail.com', 'telefono' => '3514440000', 'direccion' => 'Bv. Illia 320'], 'items' => [['sku' => 'MAL15', 'descripcion' => 'Malla sima', 'cantidad' => 4, 'precio_unit' => 45980]], 'entrega' => 'envio', 'envio' => 8000, 'total' => 191920, 'pago' => 'pagado_externo']);
            app(\App\Services\Canales\WhatsappPedidosService::class)->crearDesdeTexto($empresa, 'Hola! me mandás 30 bolsas de cemento y 12 hierros del 10 a Sarmiento 450? soy Jorge', '3516667777');
            \App\Models\Booking::create(['business_id' => $empresa->id, 'location_id' => $central->id, 'starts_at' => today()->addDay()->setTime(21, 0), 'ends_at' => today()->addDay()->setTime(23, 0), 'status' => 'pending', 'price' => 0, 'nombre' => 'Familia Gómez', 'telefono' => '3515559999', 'personas' => 4, 'origen' => 'web', 'token' => \Illuminate\Support\Str::random(40), 'notes' => 'Cumpleaños']);
            foreach (\App\Models\Comprobante::withoutGlobalScopes()->where('business_id', $empresa->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB'])->orderBy('id')->limit(15)->get() as $cf) app(\App\Services\Ventas\FidelizacionService::class)->acreditarPorComprobante($cf);

            // Fase 12: servicios con agenda de turnos, avisos al dueño y POS configurado (balanza / impresora)
            $empresa->update([
                'avisos' => ['activo' => true, 'whatsapp' => '5493515550100', 'hora' => '21:00', 'resumen_diario' => true, 'criticas' => true, 'ultimo_resumen' => null, 'ultima_critica_id' => 0],
                'pos' => ['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'imprimir_auto' => false, 'impresora' => 'navegador', 'ancho' => 42],
            ]);
            $servicios = collect([['Colocación de cerámicos (m²)', 'SRV-COL', 9500, 60], ['Asesoramiento en obra', 'SRV-ASE', 25000, 90], ['Flete y descarga', 'SRV-FLE', 18000, 45]])
                ->map(fn($s) => Product::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'rubro_id' => $rubros->first()->id, 'name' => $s[0], 'sku' => $s[1], 'tipo' => 'servicio', 'unit' => 'un', 'cost' => 0, 'price' => $s[2], 'iva' => 21, 'stock' => 0, 'active' => true, 'controla_stock' => false]));
            $clientesAg = Contact::customers()->where('business_id', $empresa->id)->where('name', '!=', 'Consumidor Final')->orderBy('id')->limit(4)->get();
            $mk = fn($dias, $h, $srv, $cli, $st) => \App\Models\Booking::create(['business_id' => $empresa->id, 'location_id' => $central->id, 'contact_id' => $cli?->id, 'service_id' => $srv->id, 'assigned_to' => $dueno->id, 'starts_at' => today()->addDays($dias)->setTime($h, 0), 'ends_at' => today()->addDays($dias)->setTime($h, 0)->addMinutes(60), 'status' => $st, 'price' => $srv->price, 'personas' => 1, 'origen' => 'manual', 'token' => \Illuminate\Support\Str::random(40)]);
            $mk(0, 9, $servicios[0], $clientesAg[0] ?? null, 'confirmed'); $mk(0, 11, $servicios[1], $clientesAg[1] ?? null, 'pending'); $mk(0, 15, $servicios[2], $clientesAg[2] ?? null, 'completed');
            $mk(1, 10, $servicios[0], $clientesAg[3] ?? null, 'confirmed'); $mk(2, 16, $servicios[1], $clientesAg[0] ?? null, 'pending'); $mk(-1, 14, $servicios[2], $clientesAg[1] ?? null, 'completed');

            // Fase 13: sueldos, bienes de uso, dólares, obra, servicio técnico y hotelería (el corralón demo tiene cabañas y taller)
            $empresa->update(['verticales_extra' => ['hoteleria', 'servicios'], 'sueldos' => ['dia_pago' => 4, 'cuenta_id' => $banco->id]]);
            $mesPasado = today()->subMonth();
            $emps = collect([['Marcelo Ortiz', '20-28111222-3', 'Vendedor B', 'Comercio CCT 130/75', 'Vendedor', '2019-03-01', 980000], ['Sofía Ledesma', '27-33444555-6', 'Administrativo A', 'Comercio CCT 130/75', 'Administración', '2022-08-15', 1050000], ['Ramón Quiroga', '20-25666777-8', 'Oficial especializado', 'UOCRA CCT 76/75', 'Depósito y obra', '2016-05-10', 1120000]])
                ->map(fn($e, $i) => \App\Models\Empleado::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'legajo' => $i + 1, 'nombre' => $e[0], 'cuil' => $e[1], 'categoria' => $e[2], 'convenio' => $e[3], 'puesto' => $e[4], 'fecha_ingreso' => $e[5], 'sueldo_basico' => $e[6], 'obra_social' => 'OSECAC', 'activo' => true]));
            $sueldos = app(\App\Services\Sueldos\SueldosService::class);
            $sueldos->anticipo($emps[2], $cajaCentral, 150000, $mesPasado->copy()->day(15)->toDateString());
            $liq = $sueldos->liquidar($empresa, $mesPasado->format('Y-m'), 'mensual', [$emps[0]->id => ['dias' => 30, 'horas_extra_50' => 6], $emps[2]->id => ['dias' => 30, 'anticipos' => 150000, 'adicionales' => 40000]]);
            $sueldos->confirmar($liq);
            $activosSvc = app(\App\Services\Contabilidad\ActivosService::class);
            $activosSvc->alta(['nombre' => 'Camioneta Toyota Hilux 2022', 'categoria' => 'rodados', 'identificacion' => 'AF 123 GH', 'fecha_alta' => today()->subMonths(14)->startOfMonth()->toDateString(), 'valor_origen' => 38000000, 'valor_residual' => 8000000, 'vida_util_meses' => 60], 'aporte');
            $activosSvc->alta(['nombre' => 'Autoelevador Heli 2.5 t', 'categoria' => 'maquinaria', 'fecha_alta' => today()->subMonths(8)->startOfMonth()->toDateString(), 'valor_origen' => 21000000, 'valor_residual' => 3000000, 'vida_util_meses' => 120], 'aporte');
            $activosSvc->alta(['nombre' => 'Notebooks administración (3)', 'categoria' => 'equipos', 'fecha_alta' => today()->subMonths(3)->startOfMonth()->toDateString(), 'valor_origen' => 2700000, 'valor_residual' => 0, 'vida_util_meses' => 36], 'aporte');
            foreach ([3, 2, 1] as $m) $activosSvc->amortizar($empresa, today()->subMonths($m)->format('Y-m'));
            $cajaUsd = CuentaFondos::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'tipo' => 'caja', 'nombre' => 'Caja fuerte USD', 'moneda' => 'USD']);
            \App\Models\Cotizacion::updateOrCreate(['business_id' => null, 'fecha' => today()->subDays(30)->toDateString(), 'tipo' => 'oficial'], ['compra' => 1380, 'venta' => 1400, 'fuente' => 'dolarapi']);
            \App\Models\Cotizacion::updateOrCreate(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'oficial'], ['compra' => 1430, 'venta' => 1450, 'fuente' => 'dolarapi']);
            \App\Models\Cotizacion::updateOrCreate(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'blue'], ['compra' => 1470, 'venta' => 1490, 'fuente' => 'dolarapi']);
            $fondos->registrar($cajaUsd, ['fecha' => today()->subDays(30)->toDateString(), 'origen' => 'apertura', 'concepto' => 'Saldo inicial', 'ingreso' => 3200, 'cotizacion' => 1400]);
            $cajaUsd->update(['cotizacion_cierre' => 1400]);
            $constructora = Contact::customers()->where('business_id', $empresa->id)->where('name', 'like', 'Constructora%')->first() ?? Contact::customers()->first(); $constructora->update(['credit_limit' => 0]);
            $fusd = $comprobantes->guardarBorrador(['contact_id' => $constructora->id, 'tipo' => 'FX', 'fecha' => today()->subDays(5)->toDateString(), 'condicion' => 'cta_cte', 'moneda' => 'USD', 'cotizacion' => 1440, 'notas' => 'Precio pactado en dólares', 'items' => [['product_id' => $productos->firstWhere('sku', 'HIE08')->id, 'cantidad' => 100, 'precio_unit' => 9.5, 'descuento' => 0]]]);
            $comprobantes->emitir($fusd);
            // Obra en curso con partes, compra imputada y un certificado
            $obras = app(\App\Services\Obras\ObrasService::class);
            $obra = $obras->guardar(['nombre' => 'Ampliación galpón · Ruta 9 km 12', 'contact_id' => $constructora->id, 'responsable_id' => $dueno->id, 'direccion' => 'Ruta 9 km 12, Córdoba', 'estado' => 'en_curso', 'fecha_inicio' => today()->subDays(40)->toDateString(), 'fecha_fin_prevista' => today()->addDays(50)->toDateString(), 'presupuesto_venta' => 18500000, 'presupuesto_costo' => 12800000, 'avance' => 45, 'descripcion' => 'Platea de 300 m², estructura metálica y cerramiento.']);
            $obras->parte($obra, ['fecha' => today()->subDays(35)->toDateString(), 'tipo' => 'material', 'product_id' => $productos->firstWhere('sku', 'CEM50')->id, 'cantidad' => 120, 'descripcion' => '']);
            $obras->parte($obra, ['fecha' => today()->subDays(30)->toDateString(), 'tipo' => 'material', 'product_id' => $productos->firstWhere('sku', 'HIE08')->id, 'cantidad' => 80, 'descripcion' => '']);
            $obras->parte($obra, ['fecha' => today()->subDays(28)->toDateString(), 'tipo' => 'mano_obra', 'empleado_id' => $emps[2]->id, 'descripcion' => 'Armado de platea', 'cantidad' => 96, 'unidad' => 'h', 'costo_unit' => 8400]);
            $obras->parte($obra, ['fecha' => today()->subDays(20)->toDateString(), 'tipo' => 'maquinaria', 'descripcion' => 'Alquiler hormigonera y vibrador', 'cantidad' => 5, 'unidad' => 'día', 'costo_unit' => 95000]);
            $obras->parte($obra, ['fecha' => today()->subDays(10).'', 'tipo' => 'subcontrato', 'descripcion' => 'Montaje estructura metálica (Herrería Paz)', 'cantidad' => 1, 'unidad' => 'gl', 'costo_unit' => 2400000]);
            $obras->certificar($obra, 30);
            $obra->update(['avance' => 45]);
            // Servicio técnico
            $ots = app(\App\Services\Servicios\OrdenesService::class);
            $cl = Contact::customers()->where('business_id', $empresa->id)->where('name', '!=', 'Consumidor Final')->orderBy('id')->get();
            $o1 = $ots->crear(['contact_id' => $cl[0]->id, 'equipo' => 'Hormigonera 130 l', 'marca_modelo' => 'Gamma', 'serie' => 'GM-2291', 'falla' => 'No arranca el motor, hace ruido y se corta.', 'prioridad' => 'alta', 'tecnico_id' => $dueno->id, 'fecha_ingreso' => today()->subDays(4)->toDateString(), 'fecha_prometida' => today()->addDay()->toDateString()]);
            $ots->actualizar($o1, ['equipo' => $o1->equipo, 'falla' => $o1->falla, 'diagnostico' => 'Capacitor quemado y carbones gastados. Se reemplazan.', 'presupuesto' => 68000]);
            $ots->item($o1, ['descripcion' => 'Capacitor 40 µF', 'tipo' => 'material', 'cantidad' => 1, 'precio_unit' => 18000]);
            $ots->item($o1, ['descripcion' => 'Juego de carbones', 'tipo' => 'material', 'cantidad' => 1, 'precio_unit' => 12000]);
            $ots->item($o1, ['descripcion' => 'Mano de obra taller (2 h)', 'tipo' => 'mano_obra', 'cantidad' => 2, 'precio_unit' => 19000]);
            $ots->tarea($o1, 'Desarmar carcasa y probar motor'); $ots->tarea($o1, 'Reemplazar capacitor'); $ots->tarea($o1, 'Prueba de carga 15 minutos');
            $o1->update(['estado' => 'en_curso', 'aprobado_en' => now()->subDay()]); $o1->tareas()->first()->update(['hecha' => true, 'hecha_en' => now()->subHours(5), 'user_id' => $dueno->id]);
            $o2 = $ots->crear(['nombre' => 'Pedro Almada', 'telefono' => '3515558833', 'equipo' => 'Amoladora angular 9"', 'marca_modelo' => 'Bosch GWS 22', 'falla' => 'Se calienta y pierde fuerza.', 'fecha_ingreso' => today()->subDays(2)->toDateString(), 'fecha_prometida' => today()->addDays(3)->toDateString()]);
            $ots->actualizar($o2, ['equipo' => $o2->equipo, 'falla' => $o2->falla, 'diagnostico' => 'Rodamientos gastados y bobinado sucio.', 'presupuesto' => 42000]); $o2->update(['estado' => 'presupuestado']);
            $o3 = $ots->crear(['contact_id' => $cl[1]->id, 'equipo' => 'Bomba sumergible 1 HP', 'marca_modelo' => 'Motorarg', 'falla' => 'No levanta agua.', 'fecha_ingreso' => today()->subDays(7)->toDateString(), 'fecha_prometida' => today()->subDay()->toDateString(), 'presupuesto' => 95000]);
            $o3->update(['estado' => 'listo', 'diagnostico' => 'Sello mecánico roto; reemplazado.']);
            $ots->crear(['contact_id' => $cl[2]->id, 'equipo' => 'Compresor 100 l', 'marca_modelo' => 'Lüsqtoff', 'falla' => 'Pierde aire por el presostato.', 'fecha_ingreso' => today()->toDateString()]);
            // Hotelería: cabañas del corralón (demo)
            $hot = app(\App\Services\Hoteleria\HoteleriaService::class);
            $habs = collect([['Cabaña 1', 'cabania', 4, 85000], ['Cabaña 2', 'cabania', 4, 85000], ['Cabaña 3', 'cabania', 6, 110000], ['Suite Sierras', 'suite', 2, 120000], ['Doble Norte', 'doble', 2, 62000]])->map(fn($h, $i) => \App\Models\Habitacion::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'nombre' => $h[0], 'tipo' => $h[1], 'capacidad' => $h[2], 'tarifa' => $h[3], 'orden' => $i + 1]));
            $e1 = $hot->reservar(['habitacion_id' => $habs[0]->id, 'nombre' => 'Familia Bustos', 'telefono' => '3515551122', 'personas' => 4, 'desde' => today()->subDays(2)->toDateString(), 'hasta' => today()->addDays(2)->toDateString(), 'origen' => 'booking']);
            $hot->checkin($e1); $hot->consumo($e1, ['descripcion' => 'Desayuno x4', 'cantidad' => 2, 'precio_unit' => 14000, 'fecha' => today()->subDay()->toDateString()]); $hot->consumo($e1, ['descripcion' => 'Leña', 'cantidad' => 1, 'precio_unit' => 6000]);
            $e2 = $hot->reservar(['habitacion_id' => $habs[3]->id, 'contact_id' => $cl[3]->id, 'personas' => 2, 'desde' => today()->toDateString(), 'hasta' => today()->addDays(3)->toDateString(), 'notas' => 'Aniversario · pidieron vista a las sierras']);
            $hot->senia($e2, 120000, $mp);
            $hot->reservar(['habitacion_id' => $habs[2]->id, 'nombre' => 'Grupo Trekking Córdoba', 'telefono' => '3515557799', 'personas' => 6, 'desde' => today()->addDays(4)->toDateString(), 'hasta' => today()->addDays(7)->toDateString(), 'origen' => 'web']);
            $e4 = $hot->reservar(['habitacion_id' => $habs[1]->id, 'nombre' => 'Laura Méndez', 'telefono' => '3515553344', 'personas' => 3, 'desde' => today()->subDays(6)->toDateString(), 'hasta' => today()->subDays(3)->toDateString()]);
            $hot->checkin($e4); $hot->consumo($e4, ['descripcion' => 'Desayuno x3', 'cantidad' => 3, 'precio_unit' => 10500]); $hot->checkout($e4); $habs[1]->update(['estado' => 'libre']);

            // Contabilidad: asientos de todo lo anterior + extracto bancario de prueba (con dos movimientos que el sistema no tiene)
            app(\App\Services\Contabilidad\ContabilidadService::class)->sincronizar($empresa->id);
            $csv = "Fecha;Concepto;Importe;Saldo\n";
            $saldoExt = 0;
            foreach (\App\Models\MovimientoFondos::where('cuenta_fondos_id', $banco->id)->orderBy('fecha')->orderBy('id')->get() as $mv) {
                $imp = (float) $mv->ingreso - (float) $mv->egreso; $saldoExt += $imp;
                if ($mv->id % 7 === 0) continue; // algunos movimientos del sistema todavía no llegaron al banco
                $csv .= $mv->fecha->format('d/m/Y') . ';' . str_replace(';', ',', mb_strtoupper(mb_substr($mv->concepto, 0, 40))) . ';' . number_format($imp, 2, ',', '.') . ';' . number_format($saldoExt, 2, ',', '.') . "\n";
            }
            $csv .= today()->subDays(2)->format('d/m/Y') . ";COMISION MANTENIMIENTO CUENTA;-12.500,00;" . number_format($saldoExt - 12500, 2, ',', '.') . "\n";
            $csv .= today()->subDays(1)->format('d/m/Y') . ";IMPUESTO LEY 25413 DEBITOS;-8.320,50;" . number_format($saldoExt - 20820.5, 2, ',', '.') . "\n";
            app(\App\Services\Contabilidad\ConciliacionService::class)->importar($banco, $csv, 'galicia_septiembre.csv');

            Alerta::emitir(['business_id' => $empresa->id, 'modulo' => 'configuracion', 'tipo' => 'afip_cert', 'severidad' => 'info', 'titulo' => 'Certificado AFIP sin cargar', 'detalle' => 'Las facturas salen simuladas hasta que cargues certificado y clave en Configuración > Puntos de venta y AFIP.', 'url' => '/configuracion/puntos-venta']);
            Auth::logout();
        });

        Artisan::call('alertas:generar');
    }
}
