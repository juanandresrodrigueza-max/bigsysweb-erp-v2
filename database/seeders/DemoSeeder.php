<?php

namespace Database\Seeders;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Empresa demo con dos sucursales, un usuario por rol y datos para ver el dashboard con vida.
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

            $otros = [
                ['name' => 'Ana Admin',     'email' => 'admin@bigsys.com.ar',    'rol' => 'administrador', 'sucursales' => [$central, $norte]],
                ['name' => 'Carla Contable','email' => 'contador@bigsys.com.ar', 'rol' => 'contador',      'sucursales' => [$central]],
                ['name' => 'Vito Vendedor', 'email' => 'vendedor@bigsys.com.ar', 'rol' => 'vendedor',      'sucursales' => [$norte]],
                ['name' => 'Caro Cajera',   'email' => 'cajero@bigsys.com.ar',   'rol' => 'cajero',        'sucursales' => [$central]],
                ['name' => 'Dario Depósito','email' => 'deposito@bigsys.com.ar', 'rol' => 'deposito',      'sucursales' => [$central, $norte]],
            ];
            foreach ($otros as $o) {
                $u = User::create(['business_id' => $empresa->id, 'name' => $o['name'], 'email' => $o['email'], 'password' => 'password', 'status' => 'active', 'role_id' => $roles[$o['rol']], 'current_location_id' => $o['sucursales'][0]->id]);
                $u->locations()->sync(collect($o['sucursales'])->mapWithKeys(fn($s) => [$s->id => ['role_id' => $roles[$o['rol']]]])->all());
            }

            $productos = collect([
                ['Cemento x 50 kg', 'CEM50', 9800, 7200, 120, 40, 'un'],
                ['Hierro 8 mm x 12 m', 'HIE08', 6500, 4900, 18, 30, 'un'],
                ['Arena fina m³', 'ARE01', 28000, 21000, 9, 5, 'm3'],
                ['Ladrillo hueco 12x18x33', 'LAD12', 520, 380, 2400, 1000, 'un'],
                ['Cal hidratada x 25 kg', 'CAL25', 4100, 3000, 6, 20, 'un'],
                ['Piedra partida m³', 'PIE01', 32000, 24500, 4, 5, 'm3'],
            ])->map(fn($p) => Product::create([
                'business_id' => $empresa->id, 'business_location_id' => $central->id,
                'name' => $p[0], 'sku' => $p[1], 'price' => $p[2], 'cost' => $p[3], 'stock' => $p[4], 'stock_min' => $p[5], 'unit' => $p[6], 'active' => true,
            ]));

            $clientes = collect([
                ['Constructora Del Valle S.A.', '30-70012345-6', 'Responsable Inscripto', 1250000],
                ['Marcelo Giménez (obra Nueva Córdoba)', '20-28765432-1', 'Monotributista', 84000],
                ['Ferretería El Tornillo', '30-65432109-8', 'Responsable Inscripto', 0],
                ['Consumidor Final', null, 'Consumidor Final', 0],
            ])->map(fn($c) => Contact::create(['business_id' => $empresa->id, 'type' => 'customer', 'name' => $c[0], 'cuit' => $c[1], 'condicion_iva' => $c[2], 'balance' => $c[3], 'is_active' => true]));

            Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Loma Negra S.A.', 'cuit' => '30-50000000-1', 'condicion_iva' => 'Responsable Inscripto', 'balance' => -640000, 'is_active' => true]);
            Contact::create(['business_id' => $empresa->id, 'type' => 'supplier', 'name' => 'Acindar Distribuidora', 'cuit' => '30-50000000-2', 'condicion_iva' => 'Responsable Inscripto', 'balance' => -215000, 'is_active' => true]);

            $customers = $clientes->map(fn($c) => Customer::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'name' => $c->name, 'email' => 'cliente' . $c->id . '@demo.com.ar', 'document' => $c->cuit, 'active' => true]));

            foreach (range(0, 29) as $i) {
                $fecha = now()->subDays(29 - $i)->setTime(rand(9, 18), rand(0, 59));
                foreach (range(1, rand(1, 4)) as $n) {
                    $sucursal = rand(0, 3) ? $central : $norte;
                    $sale = Sale::create([
                        'business_id' => $empresa->id, 'business_location_id' => $sucursal->id, 'customer_id' => $customers->random()->id,
                        'user_id' => $dueno->id, 'status' => 'confirmed', 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0,
                        'confirmed_at' => $fecha, 'created_at' => $fecha, 'updated_at' => $fecha,
                    ]);
                    $subtotal = 0;
                    foreach ($productos->random(rand(1, 3)) as $p) {
                        $qty = rand(1, 12);
                        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $p->id, 'quantity' => $qty, 'unit_price' => $p->price, 'discount' => 0, 'subtotal' => $qty * $p->price]);
                        $subtotal += $qty * $p->price;
                    }
                    $sale->update(['subtotal' => $subtotal, 'tax' => round($subtotal * 0.21, 2), 'total' => round($subtotal * 1.21, 2)]);
                }
            }

            foreach ($productos->filter(fn($p) => $p->stock <= $p->stock_min) as $p) {
                Alerta::emitir(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'modulo' => 'stock', 'tipo' => 'stock_minimo', 'severidad' => $p->stock == 0 ? 'critica' : 'aviso', 'titulo' => "{$p->name} bajo mínimo", 'detalle' => "Stock {$p->stock} {$p->unit}, mínimo {$p->stock_min}.", 'url' => '/stock', 'modelo' => 'Product', 'modelo_id' => $p->id]);
            }
            Alerta::emitir(['business_id' => $empresa->id, 'modulo' => 'clientes', 'tipo' => 'mora', 'severidad' => 'critica', 'titulo' => 'Constructora Del Valle con saldo vencido', 'detalle' => 'Debe $ 1.250.000; última factura venció hace 12 días.', 'url' => '/clientes', 'modelo' => 'Contact', 'modelo_id' => $clientes[0]->id]);
            Alerta::emitir(['business_id' => $empresa->id, 'modulo' => 'proveedores', 'tipo' => 'op_vence', 'severidad' => 'aviso', 'titulo' => 'Orden de pago a Loma Negra vence en 3 días', 'detalle' => '$ 640.000 con vencimiento el ' . now()->addDays(3)->format('d/m') . '.', 'url' => '/proveedores']);
            Alerta::emitir(['business_id' => $empresa->id, 'modulo' => 'configuracion', 'tipo' => 'afip_cert', 'severidad' => 'info', 'titulo' => 'Certificado AFIP sin cargar', 'detalle' => 'Cargá el certificado y la clave para emitir facturas electrónicas.', 'url' => '/configuracion']);
        });
    }
}
