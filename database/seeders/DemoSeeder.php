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
use App\Services\Comprobantes\CobroService;
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

            $productos = collect([
                ['Cemento x 50 kg', 'CEM50', 9800, 7200, 1400, 200, 'un'], ['Hierro 8 mm x 12 m', 'HIE08', 6500, 4900, 260, 60, 'un'],
                ['Arena fina m³', 'ARE01', 28000, 21000, 140, 20, 'm3'], ['Ladrillo hueco 12x18x33', 'LAD12', 520, 380, 9000, 2000, 'un'],
                ['Cal hidratada x 25 kg', 'CAL25', 4100, 3000, 90, 40, 'un'], ['Piedra partida m³', 'PIE01', 32000, 24500, 12, 15, 'm3'],
                ['Hierro 10 mm x 12 m', 'HIE10', 9900, 7600, 300, 60, 'un'], ['Malla sima 15x15 6mm', 'MAL15', 38000, 29000, 180, 30, 'un'],
            ])->map(fn($p) => Product::create([
                'business_id' => $empresa->id, 'business_location_id' => $central->id, 'name' => $p[0], 'sku' => $p[1],
                'price' => $p[2], 'prices' => ['2' => round($p[2] * 0.93), '3' => round($p[2] * 0.9), '4' => round($p[2] * 0.88), '5' => round($p[2] * 0.85)],
                'cost' => $p[3], 'iva' => 21, 'stock' => $p[4], 'stock_min' => $p[5], 'unit' => $p[6], 'active' => true,
            ]));

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

            Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Loma Negra S.A.', 'cuit' => '30-50000000-1', 'condicion_iva' => 'Responsable Inscripto', 'balance' => -640000, 'is_active' => true]);
            Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Acindar Distribuidora', 'cuit' => '30-50000000-2', 'condicion_iva' => 'Responsable Inscripto', 'balance' => -215000, 'is_active' => true]);

            // Un mes de facturas, algunas cobradas, dos presupuestos y un acopio, pasando por el servicio real.
            Auth::login($dueno);
            $comprobantes = app(ComprobanteService::class);
            $cobros = app(CobroService::class);
            mt_srand(7);

            $facturas = [];
            foreach (range(0, 29) as $i) {
                $fecha = today()->subDays(29 - $i);
                foreach (range(1, mt_rand(1, 3)) as $n) {
                    $suc = mt_rand(0, 3) ? $central : $norte;
                    $dueno->forceFill(['current_location_id' => $suc->id])->save();
                    $cliente = $clientes->random();
                    $items = $productos->random(mt_rand(1, 3))->map(fn($p) => ['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => mt_rand(1, 12), 'precio_unit' => $p->precioLista($cliente->lista_precios), 'descuento' => $cliente->descuento, 'alicuota_iva' => 21])->values()->all();
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
            $cobros->registrar($obra, ['fecha' => today()->subDays(12)->toDateString(), 'medios' => [['medio' => 'transferencia', 'monto' => (float) $acopio->total, 'referencia' => 'TRF 88213']], 'imputaciones' => [['comprobante_id' => $acopio->id, 'monto' => (float) $acopio->total]]]);
            app(\App\Services\Comprobantes\AcopioService::class)->retirar($acopio->fresh()->acopio, ['fecha' => today()->subDays(8)->toDateString(), 'retirado_por' => 'Camión propio', 'items' => [['acopio_item_id' => $acopio->acopio->items[0]->id, 'cantidad' => 60], ['acopio_item_id' => $acopio->acopio->items[1]->id, 'cantidad' => 1000]]]);

            // Presupuestos: uno reciente y uno viejo sin respuesta
            foreach ([[2, $clientes[4]], [12, $clientes[2]]] as [$dias, $cli]) {
                $p = $comprobantes->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'PRE', 'fecha' => today()->subDays($dias)->toDateString(), 'condicion' => 'cta_cte', 'items' => $productos->random(3)->map(fn($x) => ['product_id' => $x->id, 'descripcion' => $x->name, 'cantidad' => mt_rand(5, 40), 'precio_unit' => $x->precioLista($cli->lista_precios), 'alicuota_iva' => 21])->values()->all()]);
                $comprobantes->emitir($p);
            }

            Alerta::emitir(['business_id' => $empresa->id, 'modulo' => 'configuracion', 'tipo' => 'afip_cert', 'severidad' => 'info', 'titulo' => 'Certificado AFIP sin cargar', 'detalle' => 'Las facturas salen simuladas hasta que cargues certificado y clave en Configuración > Puntos de venta y AFIP.', 'url' => '/configuracion/puntos-venta']);
            Auth::logout();
        });

        Artisan::call('alertas:generar');
    }
}
