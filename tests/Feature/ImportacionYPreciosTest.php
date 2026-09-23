<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Contabilidad\ExtractoLectorService;
use App\Services\Migracion\ImportadorService;
use App\Services\Stock\ImportacionPreciosService;
use Tests\ErpTestCase;

class ImportacionYPreciosTest extends ErpTestCase
{
    public function test_importa_articulos_con_rubros_anidados_seis_listas_y_stock_y_no_duplica(): void
    {
        $svc = app(ImportadorService::class);
        $enc = ['Codigo', 'Descripcion', 'Rubro', 'Costo', 'Precio lista 1', 'Precio lista 6', 'Stock', 'Dto cant 1 desde', 'Dto cant 1 %'];
        $mapeo = $svc->sugerirMapeo('articulos', $enc);
        $this->assertSame('descripcion', $mapeo[1]); $this->assertSame('rubro', $mapeo[2]); $this->assertSame('precio6', $mapeo[5]);
        $fila = ['X1', 'Cemento', 'Construcción / Cementos / Portland', '1000', '1500', '1200', '25', '10', '5'];
        $imp = $svc->aplicar('articulos', [$fila], $mapeo, ['stock_inicial' => true]);
        $this->assertSame(1, $imp->creadas);
        $p = Product::where('sku', 'X1')->first();
        $this->assertSame('Construcción › Cementos › Portland', $p->rubro->nombreCompleto());
        $this->assertEqualsWithDelta(1200, $p->precioLista(6), 0.01);
        $this->assertEqualsWithDelta(25, (float) $p->stock, 0.001);
        $this->assertEqualsWithDelta(5, $p->descuentoPorCantidad(10), 0.01);
        $imp2 = $svc->aplicar('articulos', [array_replace($fila, [3 => '1100', 6 => '30'])], $mapeo, ['ajustar_stock' => true]);
        $this->assertSame(0, $imp2->creadas); $this->assertSame(1, $imp2->actualizadas);
        $this->assertEqualsWithDelta(1100, (float) $p->fresh()->cost, 0.01);
        $this->assertEqualsWithDelta(30, (float) $p->fresh()->stock, 0.001, 'Ajusta el stock del existente al valor del archivo');
        $this->assertSame(3, Rubro::count());
    }

    public function test_importa_clientes_con_saldo_y_luego_saldos_por_comprobante(): void
    {
        $svc = app(ImportadorService::class);
        $imp = $svc->aplicar('clientes', [['Pepe SRL', '30-11111111-1', 'RI', '', '', '', '', '', '2', '', '', '30', '5', '', '1000', '']], array_flip(array_keys(ImportadorService::ENTIDADES['clientes']['campos'])) ? array_combine(range(0, 15), ['nombre', 'cuit', 'condicion_iva', 'email', 'telefono', 'direccion', 'localidad', 'provincia', 'lista', 'limite_credito', 'tipo_cliente', 'dias_pago', 'descuento', 'vendedor', 'saldo', 'notas']) : []);
        $this->assertSame(1, $imp->creadas);
        $c = Contact::where('cuit', '30-11111111-1')->first();
        $this->assertSame('Responsable Inscripto', $c->condicion_iva);
        $this->assertSame(2, (int) $c->lista_precios); $this->assertSame(30, (int) $c->dias_pago);
        $this->assertEqualsWithDelta(1000, (float) $c->balance, 0.01);
        $imp2 = $svc->aplicar('saldos_clientes', [['Pepe SRL', '', 'FA 0001-00000009', '15/07/2026', '14/08/2026', '2.500,50'], ['No existe', '', 'FA 1', '', '', '10']], [0 => 'nombre', 1 => 'cuit', 2 => 'comprobante', 3 => 'fecha', 4 => 'vencimiento', 5 => 'importe']);
        $this->assertSame(1, $imp2->creadas); $this->assertSame(1, $imp2->errores);
        $m = CuentaCorriente::where('contact_id', $c->id)->where('concepto', 'like', 'FA 0001-00000009%')->first();
        $this->assertSame('2026-08-14', $m->fecha_vto->toDateString());
        $this->assertEqualsWithDelta(3500.5, (float) $c->fresh()->balance, 0.01);
    }

    public function test_exportar_e_importar_articulos_es_un_viaje_de_ida_y_vuelta(): void
    {
        $this->articulo(['name' => 'Tornillo', 'sku' => 'TOR1', 'stock_inicial' => 3]);
        $svc = app(ImportadorService::class);
        $csv = $svc->exportarCsv('articulos');
        $filas = array_map(fn($l) => str_getcsv($l, ';'), array_filter(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
        $mapeo = $svc->sugerirMapeo('articulos', $filas[0]);
        $imp = $svc->aplicar('articulos', array_slice($filas, 1), $mapeo, []);
        $this->assertSame(0, $imp->creadas); $this->assertSame(1, $imp->actualizadas); $this->assertSame(0, $imp->errores);
    }

    public function test_lista_del_proveedor_reconoce_articulos_escritos_distinto_y_respeta_decisiones(): void
    {
        $h = $this->articulo(['name' => 'Hierro 10 mm x 12 m', 'sku' => 'HIE10', 'cost' => 7500]);
        $svc = app(ImportacionPreciosService::class);
        $filas = [['Articulo', 'Precio Lista', 'Bonif'], ['HIERRO ALET. 10 MM x 12 M', '10800', '0'], ['Membrana asfaltica 4mm', '38000', '10'], ['Sin precio', '', '']];
        $mapeo = $svc->sugerirMapeo($filas[0]);
        $this->assertSame(['descripcion', 'precio_compra', 'descuento'], array_values($mapeo));
        $an = $svc->analizar($filas, $mapeo, ['encabezado' => true]);
        $f = collect($an['filas'])->keyBy('desc');
        $this->assertContains($f['HIERRO ALET. 10 MM x 12 M']['estado'], ['sugerido', 'existente'], 'Lo reconoce aunque esté escrito distinto');
        $this->assertSame($h->id, $f['HIERRO ALET. 10 MM x 12 M']['product_id']);
        $this->assertEqualsWithDelta(44, $f['HIERRO ALET. 10 MM x 12 M']['variacion'], 0.1);
        $this->assertSame('nuevo', $f['Membrana asfaltica 4mm']['estado']);
        $imp = $svc->aplicar($filas, $mapeo, ['encabezado' => true, 'margen' => 40, 'decisiones' => [1 => (string) $h->id, 2 => 'omitir']]);
        $this->assertSame(1, $imp->actualizados); $this->assertSame(0, $imp->creados);
        $this->assertEqualsWithDelta(10800, (float) $h->fresh()->cost, 0.01);
        $this->assertEqualsWithDelta(15120, (float) $h->fresh()->price, 0.01, 'Margen 40% sobre el costo');
        $this->assertNull(Product::where('name', 'like', 'Membrana%')->first(), 'La fila omitida no se crea');
    }

    public function test_lector_de_extractos_entiende_csv_con_encabezado_sin_encabezado_y_texto_pegado(): void
    {
        $l = app(ExtractoLectorService::class);
        $tmp = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($tmp, "Banco X\nCuenta 1\nFecha;Concepto;Débito;Crédito;Saldo\n02/09/2026;TRANSFERENCIA;;1000,00;11000,00\n03/09/2026;COMISION;50,00;;10950,00\n");
        $r = $l->leer($tmp, 'ext.csv');
        $this->assertCount(2, $r); $this->assertSame(1000.0, $r[0]['monto']); $this->assertSame(-50.0, $r[1]['monto']); $this->assertSame('2026-09-03', $r[1]['fecha']);
        file_put_contents($tmp, "18/09/2026;TRANSF RECIBIDA;25000,00;1.954.199,00\n19/09/2026;COMISION;350,00;1.953.849,00\n");
        $r = $l->leer($tmp, 'ext.csv');
        $this->assertSame(25000.0, $r[0]['monto']); $this->assertSame(-350.0, $r[1]['monto'], 'El signo sale de la variación del saldo');
        file_put_contents($tmp, "02/09/26TRANSFERENCIA RECIBIDA\t00458123\t458.900,001.708.900,00\n03/09/26DEBITO AUTOMATICO EPEC\t85.300,50\t1.623.599,50\nSALDO ANTERIOR\n");
        $r = $l->leer($tmp, 'ext.txt');
        $this->assertSame(458900.0, $r[0]['monto']); $this->assertSame(1708900.0, $r[0]['saldo']); $this->assertSame('00458123', $r[0]['referencia']);
        $this->assertSame(-85300.5, $r[1]['monto']);
        unlink($tmp);
    }

    public function test_actualizacion_masiva_de_precios_con_listas_puntuales_y_deshacer(): void
    {
        $p = $this->articulo(['price' => 1000, 'prices' => ['2' => 900, '3' => 800]]);
        $this->post('/stock/precios', ['modo' => 'porcentaje', 'porcentaje' => 10, 'campo' => 'price', 'listas' => [1, 3], 'redondeo' => '0'])->assertSessionHas('success');
        $p->refresh();
        $this->assertEqualsWithDelta(1100, (float) $p->price, 0.01); $this->assertEqualsWithDelta(900, (float) $p->prices['2'], 0.01, 'La lista 2 no se tocó'); $this->assertEqualsWithDelta(880, (float) $p->prices['3'], 0.01);
        $this->assertSame(1, AuditLog::where('accion', 'precios_masivo')->count());
        $this->post('/stock/precios/deshacer')->assertSessionHas('success');
        $p->refresh();
        $this->assertEqualsWithDelta(1000, (float) $p->price, 0.01); $this->assertEqualsWithDelta(800, (float) $p->prices['3'], 0.01);
        $this->postJson('/stock/precios/previsualizar', ['modo' => 'porcentaje', 'porcentaje' => 5, 'campo' => 'cost', 'ids' => [$p->id]])->assertOk()->assertJsonPath('n', 1);
    }
}
