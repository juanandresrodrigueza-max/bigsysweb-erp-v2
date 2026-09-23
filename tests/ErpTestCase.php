<?php

namespace Tests;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Deposito;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PuntoVenta;
use App\Models\Role;
use App\Models\Rubro;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Contabilidad\PlanCuentas;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

// Base de las pruebas del ERP: una empresa mínima pero completa (sucursal, dueño, roles, punto de venta, depósito, caja, banco, plan de cuentas).
abstract class ErpTestCase extends TestCase
{
    use RefreshDatabase;

    protected Business $empresa;
    protected BusinessLocation $sucursal;
    protected User $dueno;
    protected CuentaFondos $caja;
    protected CuentaFondos $banco;
    protected Deposito $deposito;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        [$this->empresa, $this->sucursal, $this->dueno, $this->caja, $this->banco, $this->deposito] = $this->crearEmpresa('Empresa Test', 'test');
        Auth::login($this->dueno);
        $this->actingAs($this->dueno);
    }

    protected function crearEmpresa(string $nombre, string $slug, string $condicionIva = 'Responsable Inscripto'): array
    {
        $dueno = User::create(['name' => "Dueño {$nombre}", 'email' => "{$slug}@test.com", 'password' => 'password', 'status' => 'active']);
        $b = Business::create(['name' => $nombre, 'slug' => $slug, 'email' => "{$slug}@test.com", 'cuit' => '30-' . str_pad((string) abs(crc32($slug) % 100000000), 8, '0', STR_PAD_LEFT) . '-' . (crc32($slug) % 10), 'razon_social' => "{$nombre} S.R.L.", 'condicion_iva' => $condicionIva, 'afip_punto_venta' => '0001', 'owner_id' => $dueno->id, 'vertical' => 'corralon']);
        $suc = BusinessLocation::create(['business_id' => $b->id, 'name' => 'Casa Central', 'short_name' => 'CC', 'is_default' => true]);
        $plan = Plan::where('slug', 'pro')->first() ?? Plan::first();
        Subscription::create(['business_id' => $b->id, 'plan_id' => $plan->id, 'status' => 'active', 'amount' => $plan->price_monthly, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth()]);
        Role::crearRolesSistema($b->id);
        $roles = $b->roles()->pluck('id', 'slug');
        $dueno->forceFill(['business_id' => $b->id, 'role_id' => $roles['dueno'], 'current_location_id' => $suc->id])->save();
        $dueno->locations()->sync([$suc->id => ['role_id' => $roles['dueno']]]);
        PuntoVenta::create(['business_id' => $b->id, 'business_location_id' => $suc->id, 'numero' => 1, 'modo' => 'electronico']);
        $dep = Deposito::create(['business_id' => $b->id, 'business_location_id' => $suc->id, 'nombre' => 'Depósito', 'es_default' => true, 'activo' => true]);
        $caja = CuentaFondos::create(['business_id' => $b->id, 'business_location_id' => $suc->id, 'tipo' => 'caja', 'nombre' => 'Caja', 'es_default' => true, 'activa' => true]);
        $banco = CuentaFondos::create(['business_id' => $b->id, 'tipo' => 'banco', 'nombre' => 'Banco', 'es_default' => true, 'activa' => true]);
        PlanCuentas::crear($b);
        Contact::create(['business_id' => $b->id, 'type' => 'customer', 'name' => 'Consumidor Final', 'condicion_iva' => 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1]);
        return [$b, $suc, $dueno, $caja, $banco, $dep];
    }

    protected function usuarioConRol(string $slug, string $email = null): User
    {
        $u = User::create(['business_id' => $this->empresa->id, 'name' => "Usuario {$slug}", 'email' => $email ?? "{$slug}@test.com", 'password' => 'password', 'status' => 'active', 'role_id' => $this->empresa->roles()->where('slug', $slug)->value('id'), 'current_location_id' => $this->sucursal->id]);
        $u->locations()->sync([$this->sucursal->id => ['role_id' => $u->role_id]]);
        return $u;
    }

    protected function cliente(array $attrs = []): Contact
    {
        return Contact::create(array_merge(['business_id' => $this->empresa->id, 'type' => 'customer', 'name' => 'Cliente RI', 'cuit' => '30-70012345-6', 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true, 'lista_precios' => 1, 'dias_pago' => 30, 'descuento' => 0, 'credit_limit' => 0], $attrs));
    }

    protected function proveedor(array $attrs = []): Contact
    {
        return Contact::create(array_merge(['business_id' => $this->empresa->id, 'type' => 'supplier', 'name' => 'Proveedor SA', 'cuit' => '30-50000000-1', 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true, 'lista_precios' => 1, 'dias_pago' => 30], $attrs));
    }

    protected function articulo(array $attrs = []): Product
    {
        static $n = 0; $n++;
        $rubro = Rubro::firstOrCreate(['business_id' => $this->empresa->id, 'nombre' => 'General', 'parent_id' => null]);
        $p = Product::create(array_merge(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'rubro_id' => $rubro->id, 'name' => "Artículo {$n}", 'sku' => "ART{$n}", 'tipo' => 'producto', 'unit' => 'un', 'cost' => 100, 'price' => 200, 'iva' => 21, 'stock' => 0, 'stock_min' => 0, 'active' => true, 'controla_stock' => true, 'moneda' => 'ARS'], $attrs));
        if (($attrs['stock_inicial'] ?? 0) > 0) app(\App\Services\Stock\StockService::class)->entrada($p, (float) $attrs['stock_inicial'], 'Stock inicial', null, $this->deposito, (float) $p->cost);
        return $p->fresh();
    }

    protected function factura(Contact $cliente, array $items, array $extra = []): \App\Models\Comprobante
    {
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $c = $svc->guardarBorrador(array_merge(['contact_id' => $cliente->id, 'tipo' => 'FX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => $items], $extra));
        return $svc->emitir($c);
    }

    // Todo asiento confirmado tiene que balancear: debe = haber.
    protected function assertAsientosBalancean(): void
    {
        foreach (\App\Models\Asiento::withoutGlobalScopes()->where('business_id', $this->empresa->id)->where('estado', 'confirmado')->with('lineas')->get() as $a) {
            $this->assertEqualsWithDelta((float) $a->lineas->sum('debe'), (float) $a->lineas->sum('haber'), 0.01, "El asiento {$a->numero} ({$a->concepto}) no balancea");
        }
    }
}
