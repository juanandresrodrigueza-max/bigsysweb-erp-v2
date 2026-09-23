<?php

namespace Tests\Unit;

use App\Models\ComprobanteItem;
use App\Services\Contabilidad\ExtractoLectorService;
use PHPUnit\Framework\TestCase;

// Cálculos puros, sin base de datos.
class CalculosTest extends TestCase
{
    public function test_redondeos_de_neto_iva_y_total(): void
    {
        $r = ComprobanteItem::calcular(3, 33.333, 0, 21);
        $this->assertSame(100.0, $r['neto']);
        $this->assertSame(21.0, $r['iva']);
        $this->assertSame(121.0, $r['total']);
        $this->assertSame(0.0, ComprobanteItem::calcular(1, 100, 100, 21)['total'], 'Descuento del 100%');
        $this->assertSame(110.5, ComprobanteItem::calcular(1, 100, 0, 10.5)['total']);
    }

    public function test_numeros_y_fechas_como_los_escriben_los_bancos(): void
    {
        $l = new ExtractoLectorService(new \App\Services\Stock\ImportacionPreciosService());
        $this->assertSame(1234.56, $l->num('1.234,56'));
        $this->assertSame(1234.56, $l->num('1,234.56'));
        $this->assertSame(-500.0, $l->num('500,00-'));
        $this->assertSame(-500.0, $l->num('(500,00)'));
        $this->assertSame(1234000.0, $l->num('$ 1.234.000'));
        $this->assertSame('2026-08-15', $l->fecha('15/08/2026'));
        $this->assertSame('2026-08-15', $l->fecha('15-08-26'));
        $this->assertSame('2026-08-15', $l->fecha('2026-08-15'));
        $this->assertSame('2026-08-15', $l->fecha('46249'), 'Número de serie de Excel');
        $this->assertNull($l->fecha('32/13/2026'));
        $this->assertNull($l->fecha('hola'));
    }
}
