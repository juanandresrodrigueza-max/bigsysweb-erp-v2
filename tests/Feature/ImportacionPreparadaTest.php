<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProveedorArticulo;
use App\Models\Rubro;
use App\Services\Stock\ImportacionPreciosService;
use Illuminate\Http\UploadedFile;
use Tests\ErpTestCase;

// Fase 34.2–34.4: preparar la lista del proveedor, reconocer cada artículo y la pantalla que salva en la importación.
class ImportacionPreparadaTest extends ErpTestCase
{
    public function test_preparar_la_lista_rango_titulos_encabezado_repetido_filtros_descripcion_armada_iva_y_celdas_combinadas(): void
    {
        $svc = app(ImportacionPreciosService::class);
        $filas = [
            ['Codigo', 'Marca', 'Articulo', 'Medida', 'Precio'],   // 1 encabezado
            ['', '', 'LISTA VIGENTE OCTUBRE', '', ''],                // 2 basura antes del rango
            ['', '', 'DISCOS', '', ''],                               // 3 título de sección
            ['D1', 'Bosch', 'Disco flap', '115', '1210'],             // 4 (con IVA 21 → 1000)
            ['D2', '', 'Disco corte', '230', '2420'],                 // 5 marca vacía: celda combinada → Bosch
            ['Codigo', 'Marca', 'Articulo', 'Medida', 'Precio'],   // 6 encabezado repetido
            ['', '', 'LIJAS', '', ''],                                // 7 título
            ['L1', 'Norton', 'Lija al agua', '220', '605'],           // 8
            ['L2', 'Norton', 'Lija DESCONTINUADA', '80', '121'],      // 9 filtro: no contiene "descontinuada"
            ['X9', 'Otra', 'Fuera del rango', '1', '99'],             // 10 fuera del hasta
        ];
        $mapeo = [0 => 'codigo', 2 => 'descripcion', 4 => 'precio_compra'];
        $prep = ['desde_fila' => 3, 'hasta_fila' => 9, 'titulos_como_rubro' => true, 'quitar_encabezado_repetido' => true, 'filtros' => [['col' => 2, 'op' => 'no_contiene', 'valor' => 'descontinuada']], 'desc_extra' => [1, 3], 'precios_con_iva' => true, 'iva_lista' => 21, 'rellenar_vacios' => [1]];
        $r = $svc->preparar($filas, $mapeo, $prep);
        $this->assertSame([0, 3, 4, 7], array_keys($r['filas']));
        $this->assertSame('Disco flap Bosch 115', $r['filas'][3][2]);
        $this->assertSame('Disco corte Bosch 230', $r['filas'][4][2], 'la marca vacía se rellena con la de arriba');
        $this->assertSame('1000.00', $r['filas'][3][4]); $this->assertSame('500.00', $r['filas'][7][4]);
        $this->assertSame(['Discos', 'Lijas'], $r['titulos']);
        $this->assertSame('Lijas', $r['rubro_fila'][7]);

        // Al importar con la preparación, los nuevos toman el rubro del título y quedan con el costo sin IVA
        $imp = $svc->aplicar($filas, $mapeo, ['encabezado' => true, 'prep' => $prep, 'margen' => 50]);
        $this->assertSame(3, $imp->creados); $this->assertSame(0, $imp->errores);
        $d = Product::where('sku', 'D1')->firstOrFail();
        $this->assertSame('Disco flap Bosch 115', $d->name); $this->assertEqualsWithDelta(1000, (float) $d->cost, 0.01); $this->assertSame('Discos', $d->rubro->nombre);
        $this->assertSame('Lijas', Product::where('sku', 'L1')->firstOrFail()->rubro->nombre);
        $this->assertNull(Product::where('sku', 'L2')->first()); $this->assertNull(Product::where('sku', 'X9')->first());
    }

    public function test_reconoce_por_codigo_del_proveedor_fabricante_en_descripcion_y_anota_el_codigo(): void
    {
        $prov = $this->proveedor(['name' => 'Ferretera Sur']);
        $a = $this->articulo(['name' => 'Amoladora angular 115', 'sku' => 'AMO115', 'barcode' => '7791234567890', 'cost' => 50000, 'price' => 80000]);
        $b = $this->articulo(['name' => 'Taladro percutor', 'sku' => 'TAL500', 'cost' => 30000, 'price' => 45000]);
        ProveedorArticulo::create(['business_id' => $this->empresa->id, 'contact_id' => $prov->id, 'codigo' => 'FS-0099', 'descripcion' => 'AMOLADORA 115 PROF', 'product_id' => $a->id]);
        $svc = app(ImportacionPreciosService::class);
        $filas = [['Cod', 'Descripcion', 'Precio'], ['FS-0099', 'AMOLADORA 115 PROF', '55000'], ['FS-0100', 'Taladro perc. ref TAL500 c/maletin', '33000'], ['FS-0101', 'Pinza pico de loro 10', '8000']];
        $mapeo = [0 => 'codigo', 1 => 'descripcion', 2 => 'precio_compra'];
        $an = $svc->analizar($filas, $mapeo, ['encabezado' => true, 'contact_id' => $prov->id]);
        $f = collect($an['filas'])->keyBy('codigo');
        $this->assertSame($a->id, $f['FS-0099']['product_id']); $this->assertSame('código del proveedor', $f['FS-0099']['como']);
        $this->assertSame($b->id, $f['FS-0100']['product_id']); $this->assertSame('código de fabricante en la descripción', $f['FS-0100']['como']);
        $this->assertSame('nuevo', $f['FS-0101']['estado']);
        $this->assertSame(['código del proveedor' => 1, 'código de fabricante en la descripción' => 1], $an['resumen']['por_como']);

        // Sólo el precio de compra: el costo cambia, la venta no. Y queda anotado el código del proveedor para la próxima.
        $imp = $svc->aplicar($filas, $mapeo, ['encabezado' => true, 'contact_id' => $prov->id, 'solo_costo' => true, 'crear' => false, 'recordar' => true, 'prep' => ['factor' => 1]]);
        $this->assertSame(2, $imp->actualizados); $this->assertSame(1, $imp->errores);
        $this->assertEqualsWithDelta(33000, (float) $b->fresh()->cost, 0.01); $this->assertEqualsWithDelta(45000, (float) $b->fresh()->price, 0.01);
        $this->assertSame($b->id, ProveedorArticulo::where('contact_id', $prov->id)->where('codigo', 'FS-0100')->value('product_id'));
        $this->assertSame($mapeo, array_map('strval', $svc->mapeoRecordado($prov->id)['mapeo']));
        $this->assertTrue($svc->mapeoRecordado($prov->id)['opt']['solo_costo']);
    }

    public function test_la_pantalla_que_salva_marca_lo_raro_destildado_y_graba_todo_o_nada(): void
    {
        $prov = $this->proveedor(['name' => 'Mayorista Norte']);
        $a = $this->articulo(['name' => 'Yerba 1 kg', 'sku' => 'YER1', 'cost' => 2000, 'price' => 3000]);
        $b = $this->articulo(['name' => 'Azúcar 1 kg', 'sku' => 'AZU1', 'cost' => 900, 'price' => 1200]);
        $svc = app(ImportacionPreciosService::class);
        $filas = [['Cod', 'Desc', 'Costo', 'Venta'], ['YER1', 'Yerba 1 kg', '2100', '3100'], ['AZU1', 'Azúcar 1 kg', '1800', '1500'], ['AZU1', 'Azúcar 1 kg (repetida)', '950', ''], ['NUE1', 'Harina 000', '0', ''], ['NUE2', 'Aceite 900', 'consultar', ''], ['NUE3', 'Arroz 1 kg', '700', '1000']];
        $mapeo = [0 => 'codigo', 1 => 'descripcion', 2 => 'costo', 3 => 'precio_venta'];
        $an = $svc->analizar($filas, $mapeo, ['encabezado' => true, 'contact_id' => $prov->id, 'limite_pct' => 50, 'crear_nuevos' => false]);
        $f = collect($an['filas'])->keyBy('fila');
        $this->assertNull($f[1]['alerta']); $this->assertTrue($f[1]['incluir']);
        $this->assertSame('bajo_costo', $f[2]['alerta']); // sube 100 % y además venta bajo el costo: manda lo más grave
        $this->assertSame('repetido', $f[3]['alerta']);
        $this->assertSame('cero', $f[4]['alerta']);
        $this->assertSame('cero', $f[5]['alerta']); // texto en vez de número
        $this->assertSame('no_encontrado', $f[6]['alerta']);
        $this->assertSame(5, $an['resumen']['con_alerta']);

        // Todo o nada: con una fila sin precio tildada, no se importa ninguna
        try { $svc->aplicar($filas, $mapeo, ['encabezado' => true, 'crear' => false, 'todo_o_nada' => true, 'decisiones' => [2 => 'omitir', 3 => 'omitir', 4 => 'omitir', 6 => 'omitir']]); $this->fail('Debía fallar'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('No se importó nada', $e->errors()['archivo'][0]); }
        $this->assertEqualsWithDelta(2000, (float) $a->fresh()->cost, 0.01, 'se deshizo todo');
        // Destildando lo raro, entra lo bueno
        $imp = $svc->aplicar($filas, $mapeo, ['encabezado' => true, 'crear' => false, 'todo_o_nada' => true, 'decisiones' => [2 => 'omitir', 3 => 'omitir', 4 => 'omitir', 5 => 'omitir', 6 => 'omitir']]);
        $this->assertSame(1, $imp->actualizados); $this->assertEqualsWithDelta(2100, (float) $a->fresh()->cost, 0.01); $this->assertEqualsWithDelta(3100, (float) $a->fresh()->price, 0.01);
        $this->assertEqualsWithDelta(900, (float) $b->fresh()->cost, 0.01);

        // Por HTTP: el mapeo recordado vuelve al subir la próxima lista del proveedor
        $svc->recordarMapeo($prov->id, $mapeo, ['titulos_como_rubro' => true], ['solo_costo' => true]);
        $csv = UploadedFile::fake()->createWithContent('lista.csv', "Cod;Desc;Costo;Venta\nYER1;Yerba 1 kg;2200;3300\n");
        $r = $this->post('/stock/importar/previsualizar', ['archivo' => $csv, 'contact_id' => $prov->id])->assertOk()->json();
        $this->assertSame('costo', $r['mapeo'][2]); $this->assertTrue($r['recordado']['prep']['titulos_como_rubro']);
        $this->get('/stock/importar')->assertInertia(fn($p) => $p->component('Stock/Importar', false)->where('proveedores.0.recordado', today()->toDateString()));
    }
}
