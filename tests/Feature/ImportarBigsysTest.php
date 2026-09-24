<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Services\Migracion\ImportadorService;
use Illuminate\Http\UploadedFile;
use Tests\ErpTestCase;

// Fase 25.6: importar desde BigSys Clarion (tablas cta, art y mov con sus nombres de campo).
class ImportarBigsysTest extends ErpTestCase
{
    private function importar(string $entidad, string $csv, array $opt = []): void
    {
        $svc = app(ImportadorService::class);
        $path = tempnam(sys_get_temp_dir(), 'imp'); file_put_contents($path, $csv);
        $filas = $svc->leer($path, 'x.csv');
        $mapeo = $svc->sugerirMapeo($entidad, $filas[0], 'bigsys');
        $svc->aplicar($entidad, array_slice($filas, 1), $mapeo, $opt);
    }

    public function test_cuentas_separadas_por_tipo_con_codigo_condicion_y_lista(): void
    {
        // CP1252 como lo exporta Windows: "Martínez" con í = 0xED.
        $csv = "codcta;tipcta;nombre;nroiva;codiva;direcc;locali;telefo;mail;lispre;pordes;limcre\n"
            . "112;C;RODRIGUEZ FERNANDO GERMAN;20-36416926-5;RI;Av. Colon 100;Cordoba;3511111111;fer@mail.com;4;5;100000\n"
            . "113;C;" . mb_convert_encoding('Kiosco Martínez', 'Windows-1252', 'UTF-8') . ";;CF;;;;;1;0;0\n"
            . "114;C;Monotributo SA;20-12345678-6;MT;;;;;2;0;0\n"
            . "900;P;Loma Negra SA;30-50000000-1;RI;;;;;;;\n"
            . "7001;E;Empleado Pérez;;;;;;;;;\n";
        $antes = Contact::customers()->count();
        $this->importar('clientes', $csv);
        $this->assertSame($antes + 3, Contact::customers()->count());
        $f = Contact::customers()->where('codigo', '112')->first();
        $this->assertSame(['RODRIGUEZ FERNANDO GERMAN', '20-36416926-5', 'Responsable Inscripto', 4, 5.0, 100000.0], [$f->name, $f->cuit, $f->condicion_iva, (int) $f->lista_precios, (float) $f->descuento, (float) $f->credit_limit]);
        $this->assertSame('Kiosco Martínez', Contact::where('codigo', '113')->value('name'), 'Acentos de Windows-1252');
        $this->assertSame('Consumidor Final', Contact::where('codigo', '113')->value('condicion_iva'));
        $this->assertSame('Monotributista', Contact::where('codigo', '114')->value('condicion_iva'));
        $this->assertStringContainsString('2 filas de otro tipo', \App\Models\Importacion::latest('id')->first()->detalle[0]);
        // El mismo archivo como proveedores: solo el P.
        $this->importar('proveedores', $csv);
        $this->assertSame(1, Contact::suppliers()->where('codigo', '900')->where('name', 'Loma Negra SA')->count());
        $this->assertSame(0, Contact::where('codigo', '7001')->count(), 'Los empleados no se importan');
        // Reimportar actualiza por código, no duplica.
        $this->importar('clientes', "codcta;tipcta;nombre;lispre\n112;C;Fernando Rodriguez;3\n");
        $this->assertSame($antes + 3, Contact::customers()->count());
        $this->assertSame(3, (int) Contact::where('codigo', '112')->value('lista_precios'));
    }

    public function test_articulos_con_seis_listas_finales_a_neto_rubro_y_estado(): void
    {
        $csv = "codart;descri;codbar;desrub;prelis[1];prelis[2];prelis[3];prelis[4];prelis[5];prelis[6];poriva;precos;stomin;stoact;estado\n"
            . "11801;Vauquita Alfajor Mousse;7790001;Golosinas / Alfajores;1210;1089;968;;;;21;500;24;120;A\n"
            . "999;Articulo dado de baja;;Golosinas;121;;;;;;21;50;0;0;B\n";
        $this->importar('articulos', $csv, ['precios_con_iva' => true]);
        $p = Product::where('sku', '11801')->first();
        $this->assertNotNull($p);
        $this->assertEquals(1000, (float) $p->price, 'Lista 1: 1210 con IVA → 1000 neto');
        $this->assertEquals(900, (float) $p->prices['2']);
        $this->assertEquals(800, (float) $p->prices['3']);
        $this->assertSame('Golosinas › Alfajores', $p->rubro->nombreCompleto());
        $this->assertEquals(24, (float) $p->stock_min);
        $this->assertEquals(120, (float) $p->stock);
        $this->assertFalse((bool) Product::where('sku', '999')->value('active'), 'Estado B = baja');
    }

    public function test_saldos_desde_movimientos_sin_anulados_ni_totsal(): void
    {
        $this->importar('clientes', "codcta;tipcta;nombre\n112;C;Fernando\n113;C;Kiosco\n");
        // Fechas en formato Clarion (días desde el 28/12/1800): 82000 = 2025-07-06 aprox.
        $mov = "codcta;tipcta;tipasi;nrocom;fecha;fecven;totdeb;totcre;totsal;movanu\n"
            . "112;C;VentaFac;1001;82000;82030;10000;0;10000;\n"
            . "112;C;VentaPag;501;82010;;0;4000;0;\n"
            . "112;C;VentaFac;1002;82020;82050;5000;0;5000;S\n"     // anulada
            . "112;C;VentaPag;502;82025;;0;1000;0;\n"               // pago a cuenta: totsal no lo descuenta
            . "113;C;VentaFac;1003;82000;;2500;0;2500;\n"
            . "900;P;ComprFac;77;82000;;0;9999;9999;\n";            // otra entidad
        $this->importar('saldos_clientes', $mov, ['fecha_saldos' => null]);
        $this->assertEquals(5000, (float) Contact::where('codigo', '112')->value('balance'), '10.000 − 4.000 − 1.000, sin la anulada');
        $this->assertEquals(2500, (float) Contact::where('codigo', '113')->value('balance'));
        $mv = \App\Models\CuentaCorriente::where('contact_id', Contact::where('codigo', '112')->value('id'))->first();
        $this->assertSame('Saldo BigSys (migración)', $mv->concepto);
        $this->assertSame(\Carbon\Carbon::create(1800, 12, 28)->addDays(82025)->toDateString(), $mv->fecha->toDateString(), 'Fecha del último movimiento, convertida de Clarion');
    }

    public function test_desde_la_pantalla_con_el_perfil(): void
    {
        $f = UploadedFile::fake()->createWithContent('cta.csv', "codcta;tipcta;nombre;nroiva;codiva\n112;C;Fernando;20-36416926-5;RI\n");
        $this->post('/configuracion/importar/previsualizar', ['archivo' => $f, 'entidad' => 'clientes', 'perfil' => 'bigsys'])->assertSessionHas('preview', fn($p) => $p['mapeo'][0] === 'codigo' && $p['mapeo'][1] === 'tipo_cuenta' && $p['mapeo'][3] === 'cuit' && $p['perfil'] === 'bigsys');
    }
}
