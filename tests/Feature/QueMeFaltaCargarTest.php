<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Services\Compras\AfipCsvImportService;
use Tests\ErpTestCase;

// Fase 31.6 · Qué me falta cargar: el CSV de Mis Comprobantes recibidos contra lo cargado.
class QueMeFaltaCargarTest extends ErpTestCase
{
    private function csv(array $filas): string
    {
        $cab = 'Fecha de Emisión;Tipo de Comprobante;Punto de Venta;Número Desde;Número Hasta;Cód. Autorización;Tipo Doc. Emisor;Nro. Doc. Emisor;Denominación Emisor;Tipo Cambio;Moneda;Imp. Neto Gravado;Imp. Neto No Gravado;Imp. Op. Exentas;Otros Tributos;IVA;Imp. Total';
        return implode("\n", [$cab, ...array_map(fn($f) => "{$f[0]};{$f[1]};{$f[2]};{$f[3]};{$f[3]};7123;80;{$f[4]};{$f[5]};1,000000;PES;{$f[6]};0,00;0,00;0,00;{$f[7]};{$f[8]}", $filas)]);
    }

    private function compra($prov, string $tipo, string $numero, float $neto, array $extra = []): Comprobante
    {
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => $tipo, 'numero_proveedor' => $numero, 'fecha' => '2026-10-10', 'registrar' => true, 'condicion' => 'cta_cte',
            'items' => [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => $neto, 'alicuota_iva' => 21]]] + $extra)->assertSessionHasNoErrors();
        return Comprobante::compras()->orderByDesc('id')->first();
    }

    public function test_el_cruce_dice_que_falta_sin_cuit_otro_importe_dos_veces_y_que_no_cierra(): void
    {
        $a = $this->proveedor(['name' => 'Alfa SA', 'cuit' => '30-50000000-1']);
        $b = $this->proveedor(['name' => 'Beta SRL', 'cuit' => '30-60000000-2']);
        $sin = $this->proveedor(['name' => 'Gamma sin cuit', 'cuit' => null]);
        $this->compra($a, 'FA', '0001-00000001', 1000);                 // coincide
        $this->compra($a, 'FA', '0001-00000002', 1000);                 // en ARCA figura por 2420: otro importe
        $this->compra($sin, 'FA', '0005-00000009', 500);                // cargada a un proveedor sin CUIT
        $dup = $this->compra($b, 'FA', '0002-00000007', 300); $d2 = $dup->replicate(); $d2->save(); // dos veces (el formulario lo frena; pasó por otro lado)
        $rota = $this->compra($b, 'FA', '0002-00000008', 100); $rota->forceFill(['total' => 150])->save(); // no cierra
        $csv = $this->csv([
            ['10/10/2026', 1, 1, 1, '30500000001', 'ALFA SA', '1.000,00', '210,00', '1.210,00'],
            ['11/10/2026', 1, 1, 2, '30500000001', 'ALFA SA', '2.000,00', '420,00', '2.420,00'],
            ['12/10/2026', 1, 5, 9, '30700000003', 'GAMMA', '500,00', '105,00', '605,00'],
            ['13/10/2026', 1, 2, 7, '30600000002', 'BETA SRL', '300,00', '63,00', '363,00'],
            ['14/10/2026', 1, 3, 50, '30600000002', 'BETA SRL', '10.000,00', '2.100,00', '12.100,00'],   // falta (la de más IVA)
            ['15/10/2026', 6, 4, 77, '20300000004', 'KIOSCO NUEVO', '0,00', '0,00', '800,00'],           // falta, proveedor nuevo, sin IVA (factura B)
            ['16/10/2026', 1, 2, 8, '30600000002', 'BETA SRL', '100,00', '21,00', '121,00'],             // cargada, pero por 150 (no cierra y otro importe)
        ]);
        $r = app(AfipCsvImportService::class)->cruzar($csv);
        $this->assertSame(7, $r['filas']); $this->assertSame(2, $r['ok']);
        $this->assertCount(2, $r['faltan']); $this->assertSame('0003-00000050', $r['faltan'][0]['numero']); $this->assertTrue($r['faltan'][1]['proveedor_nuevo']); $this->assertSame('FB', $r['faltan'][1]['tipo']);
        $this->assertEquals(2100, $r['iva_faltante']); $this->assertSame(1, $r['proveedores_nuevos']);
        $this->assertCount(1, $r['sin_cuit']); $this->assertSame('Gamma sin cuit', $r['sin_cuit'][0]['cargada_a']); $this->assertSame('30-70000000-3', $r['sin_cuit'][0]['cuit']);
        $this->assertCount(2, $r['otro_importe']); $this->assertEquals(-1210, collect($r['otro_importe'])->firstWhere('numero', '0001-00000002')['diferencia']);
        $this->assertCount(1, $r['duplicadas']); $this->assertSame(2, $r['duplicadas'][0]['veces']);
        $this->assertCount(1, $r['no_cierran']); $this->assertEquals(29, $r['no_cierran'][0]['diferencia']);
        $this->assertSame(['desde' => '2026-10-10', 'hasta' => '2026-10-16'], $r['periodo']);
    }

    public function test_desde_el_cruce_se_crean_solo_las_que_faltan_sin_volver_a_subir_el_archivo(): void
    {
        $a = $this->proveedor(['name' => 'Alfa SA', 'cuit' => '30-50000000-1']);
        $this->compra($a, 'FA', '0001-00000001', 1000);
        $csv = $this->csv([
            ['10/10/2026', 1, 1, 1, '30500000001', 'ALFA SA', '1.000,00', '210,00', '1.210,00'],
            ['14/10/2026', 1, 3, 50, '30500000001', 'ALFA SA', '10.000,00', '2.100,00', '12.100,00'],
            ['15/10/2026', 6, 4, 77, '20300000004', 'KIOSCO NUEVO', '0,00', '0,00', '800,00'],
        ]);
        $f = \Illuminate\Http\UploadedFile::fake()->createWithContent('recibidos.csv', $csv);
        $r = $this->post('/proveedores/compras/cruzar-afip', ['archivo' => $f])->assertOk()->json();
        $this->assertCount(2, $r['faltan']); $this->assertNotEmpty($r['token']);
        // sólo la de Alfa: la del kiosco nuevo se deja para después
        $this->post('/proveedores/compras/importar-afip', ['token' => $r['token'], 'solo' => [$r['faltan'][0]['clave']], 'registrar' => false])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, Comprobante::compras()->where('contact_id', $a->id)->count());
        $nueva = Comprobante::compras()->where('numero_proveedor', '0003-00000050')->first();
        $this->assertSame('borrador', $nueva->estado); $this->assertEquals(12100, $nueva->total);
        $this->assertSame(0, \App\Models\Contact::where('name', 'like', '%KIOSCO%')->count());
        // volver a cruzar: ahora falta sólo la del kiosco
        $r2 = app(AfipCsvImportService::class)->cruzar($csv);
        $this->assertCount(1, $r2['faltan']); $this->assertSame('0004-00000077', $r2['faltan'][0]['numero']);
        // un archivo que no es el de ARCA
        $this->post('/proveedores/compras/cruzar-afip', ['archivo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('x.csv', "a;b\n1;2")])->assertStatus(422);
        // token vencido
        $this->post('/proveedores/compras/importar-afip', ['token' => 'nada', 'solo' => ['x']])->assertStatus(422);
    }
}
