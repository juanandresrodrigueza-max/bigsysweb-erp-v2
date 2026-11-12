<?php

namespace Tests\Feature;

use App\Services\Producto\AyudaService;
use Tests\ErpTestCase;

// Fase 81: estados vacíos que dicen qué hacer y un consejo por día en la primera semana.
class VaciosTipsFase81Test extends ErpTestCase
{
    public function test_el_usuario_nuevo_recibe_un_consejo_por_dia_y_despues_de_la_semana_no(): void
    {
        $tip = $this->actingAs($this->dueno)->get('/dashboard')->assertOk()->viewData('page')['props']['tip'];
        $this->assertSame('buscar', $tip['clave']); $this->assertStringContainsString('Ctrl K', $tip['texto']);

        $this->dueno->forceFill(['created_at' => now()->subDays(1)])->save();
        $this->assertSame('ayuda', AyudaService::tipDelDia($this->dueno->fresh())['clave']);
        $this->dueno->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->assertNull(AyudaService::tipDelDia($this->dueno->fresh()));
        $this->assertNull($this->actingAs($this->dueno->fresh())->get('/dashboard')->viewData('page')['props']['tip']);
    }

    public function test_los_consejos_cubren_ctrl_k_el_signo_de_pregunta_y_el_asistente(): void
    {
        $tips = AyudaService::tipsPrimeraSemana();
        $this->assertCount(7, $tips, 'Uno por día de la primera semana');
        $todo = implode(' ', array_column($tips, 'texto'));
        $this->assertStringContainsString('Ctrl K', $todo); $this->assertStringContainsString('El ? del encabezado', $todo); $this->assertStringContainsString('asistente', $todo);
        $this->assertSame(count($tips), count(array_unique(array_column($tips, 'clave'))));
    }

    public function test_los_listados_usan_el_estado_vacio_y_distinguen_los_filtros(): void
    {
        $this->assertFileExists(resource_path('js/Components/EstadoVacio.vue'));
        $comp = file_get_contents(resource_path('js/Components/EstadoVacio.vue'));
        $this->assertStringContainsString('Nada con estos filtros', $comp); $this->assertStringContainsString('Limpiar filtros', $comp);
        foreach (['Clientes/Index', 'Proveedores/Index', 'Comprobantes/Index', 'Proveedores/Compras', 'Proveedores/Ordenes'] as $p) {
            $v = file_get_contents(resource_path("js/Pages/$p.vue"));
            $this->assertStringContainsString(':filtros="f"', $v, "$p distingue vacío de filtrado"); $this->assertStringContainsString(':accion=', $v, "$p dice qué hacer");
        }
        $con = collect(glob(resource_path('js/Pages/**/*.vue')))->filter(fn($f) => str_contains(file_get_contents($f), '<EstadoVacio'))->count();
        $this->assertGreaterThanOrEqual(60, $con, 'El estado vacío es el estándar, no la excepción');
        $this->assertStringContainsString('consejo por día', file_get_contents(base_path('database/migrations/2027_01_04_000001_fase81_novedad_vacios.php')));
    }
}
