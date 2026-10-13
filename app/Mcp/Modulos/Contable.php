<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Comprobante;
use App\Models\User;
use App\Services\Contabilidad\ContabilidadService;

// Contable: estado de resultados e IVA del período.
class Contable
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'resultado_periodo', 'titulo' => 'Resultado del período', 'modulo' => 'contable', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Estado de resultados: ingresos y egresos por cuenta contable y la ganancia o pérdida del período.',
                'parametros' => Busca::PERIODO,
                'ejecutar' => function (User $u, array $a) {
                    [$d, $h, $txt] = Busca::periodo($a);
                    return ['periodo' => $txt] + app(ContabilidadService::class)->resultado($u->business_id, $d->toDateString(), $h->toDateString());
                }],
            ['nombre' => 'iva_periodo', 'titulo' => 'IVA del período', 'modulo' => 'contable', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'IVA débito (ventas) menos IVA crédito (compras) del período, con las percepciones. Es una estimación: la posición final la confirma el contador.',
                'parametros' => Busca::PERIODO,
                'ejecutar' => function (User $u, array $a) {
                    [$d, $h, $txt] = Busca::periodo($a);
                    $suma = function (string $dir) use ($d, $h) {
                        $c = Comprobante::where('direccion', $dir)->emitidos()->fiscales()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC', 'NCA', 'NCB', 'NCC', 'NCE'])->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->get();
                        $s = fn($k) => round((float) $c->sum(fn($x) => ($x->esNotaCredito() ? -1 : 1) * (float) $x->$k), 2);
                        return ['neto' => $s('neto'), 'iva' => $s('iva'), 'percepciones' => $s('percepciones'), 'comprobantes' => $c->count()];
                    };
                    $v = $suma('venta'); $c = $suma('compra');
                    return ['periodo' => $txt, 'ventas' => $v, 'compras' => $c, 'saldo_iva' => round($v['iva'] - $c['iva'], 2), 'a_pagar' => $v['iva'] - $c['iva'] > 0];
                }],
        ];
    }
}
