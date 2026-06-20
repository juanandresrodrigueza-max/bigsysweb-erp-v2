
<?php namespace Tests\Feature\Api; use App\Models\Customer; use App\Models\Product; use App\Models\Sale; use App\Models\SaleItem; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase; class ReportApiTest extends TestCase { use RefreshDatabase; private User $user; protected function setUp(): void { parent::setUp(); $this->user = User::factory()->create(); } public function test_sales_summary_returns_correct_structure(): void { $this->actingAs($this->user) ->getJson('/api/reports/sales') ->assertOk() ->assertJsonStructure(['period', 'summary', 'by_day']); } public function test_sales_summary_filters_by_date(): void { $customer = Customer::factory()->create(); Sale::factory()->create([ 'customer_id' => $customer->id, 'user_id' => $this->user->id, 'status' => 'confirmed', 'total' => 500, 'created_at' => now()->subMonth(), ]); $this->actingAs($this->user) ->getJson('/api/reports/sales?from=' . now()->startOfMonth()->toDateString() . '&to=' . now()->endOfMonth()->toDateString()) ->assertOk() ->assertJsonPath('summary.total_sales', 0); } public function test_top_products_returns_list(): void { $this->actingAs($this->user) ->getJson('/api/reports/top-products') ->assertOk() ->assertJsonStructure(['data']); } public function test_stock_alerts_returns_low_stock_products(): void { Product::factory()->create(['stock' => 2, 'stock_min' => 5, 'active' => true]); Product::factory()->create(['stock' => 10, 'stock_min' => 5, 'active' => true]); $this->actingAs($this->user) ->getJson('/api/reports/stock-alerts') ->assertOk() ->assertJsonPath('total', 1); } public function test_customer_stats_returns_top_customers(): void { Customer::factory(3)->create(); $this->actingAs($this->user) ->getJson('/api/reports/customers') ->assertOk() ->assertJsonStructure(['data']); } } EOF cat > tests/Feature/Api/StockMovementApiTest.php << 'EOF' <?php namespace Tests\Feature\Api; use App\Models\Product; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase; class StockMovementApiTest extends TestCase { use RefreshDatabase; private User $user; private Product $product; protected function setUp(): void { parent::setUp(); $this->user = User::factory()->create(); $this->product = Product::factory()->create(['stock' => 20]); } public function test_can_list_stock_movements(): void { $this->actingAs($this->user) ->getJson('/api/stock-movements') ->assertOk() ->assertJsonStructure(['data']); } public function test_can_create_stock_in(): void { $this->actingAs($this->user) ->postJson('/api/stock-movements', [ 'product_id' => $this->product->id, 'type' => 'in', 'quantity' => 10, 'reason' => 'Compra proveedor', ]) ->assertCreated() ->assertJsonPath('data.type', 'in'); $this->assertEquals(30, $this->product->fresh()->stock); } public function test_can_create_stock_out(): void { $this->actingAs($this->user) ->postJson('/api/stock-movements', [ 'product_id' => $this->product->id, 'type' => 'out', 'quantity' => 5, 'reason' => 'Ajuste manual', ]) ->assertCreated(); $this->assertEquals(15, $this->product->fresh()->stock); } public function test_stock_movement_records_before_and_after(): void { $this->actingAs($this->user) ->postJson('/api/stock-movements', [ 'product_id' => $this->product->id, 'type' => 'in', 'quantity' => 8, ]) ->assertCreated(); $this->assertDatabaseHas('stock_movements', [ 'product_id' => $this->product->id, 'stock_before' => 20, 'stock_after' => 28, ]); } public function test_cannot_create_movement_with_invalid_type(): void { $this->actingAs($this->user) ->postJson('/api/stock-movements', [ 'product_id' => $this->product->id, 'type' => 'invalid', 'quantity' => 5, ]) ->assertUnprocessable(); } } EOF git add . \ && git commit -m "feat: add Scribe API docs config and integration tests for reports and stock (Week 4)" \ && git push origin main ``` 
cat > config/scribe.php << 'EOF'
<?php

return [
    'theme' => 'default',
    'title' => 'BigSysWeb ERP API',
    'description' => 'API REST para el sistema ERP BigSysWeb. Gestión de clientes, productos, ventas e inventario.',
    'base_url' => env('APP_URL', 'http://localhost'),
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains'  => ['*'],
            ],
            'include' => [],
            'exclude' => [],
        ],
    ],
    'auth' => [
        'enabled'     => true,
        'default'     => true,
        'in'          => 'bearer',
        'name'        => 'Authorization',
        'use_value'   => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{TU_TOKEN}',
        'extra_info'  => 'Obtené tu token con POST /api/auth/login',
    ],
    'type'       => 'static',
    'static'     => ['output_path' => 'public/docs'],
    'laravel'    => ['add_routes' => true, 'docs_url' => '/docs'],
    'try_it_out' => ['enabled' => true, 'base_url' => null, 'use_csrf' => false],
    'logo'         => false,
    'last_updated' => 'Actualizado automáticamente',
    'examples'     => ['faker_seed' => 1234, 'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst']],
    'strategies'   => [
        'metadata'        => [\Knuckles\Scribe\Extracting\Strategies\Metadata\GetFromDocBlocks::class],
        'urlParameters'   => [\Knuckles\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI::class],
        'queryParameters' => [\Knuckles\Scribe\Extracting\Strategies\QueryParameters\GetFromFormRequest::class],
        'headers'         => [\Knuckles\Scribe\Extracting\Strategies\Headers\GetFromRouteRules::class],
        'bodyParameters'  => [\Knuckles\Scribe\Extracting\Strategies\BodyParameters\GetFromFormRequest::class],
        'responses'       => [
            \Knuckles\Scribe\Extracting\Strategies\Responses\UseResponseAttributes::class,
            \Knuckles\Scribe\Extracting\Strategies\Responses\UseApiResourceTags::class,
            \Knuckles\Scribe\Extracting\Strategies\Responses\ResponseCalls::class,
        ],
        'responseFields'  => [\Knuckles\Scribe\Extracting\Strategies\ResponseFields\GetFromResponseFieldAttribute::class],
    ],
    'routeMatcher' => \Knuckles\Scribe\Matching\RouteMatcher::class,
];
