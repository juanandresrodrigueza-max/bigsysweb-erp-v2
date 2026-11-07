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
            // Fase 45.2: "mandale a Ferretería López el resumen de octubre".
            ['nombre' => 'enviar_resumen_a_empresa', 'titulo' => 'Mandarle el resumen del mes a una empresa', 'modulo' => 'contable', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Manda por mail a una empresa que administra el estudio su resumen del mes (ventas, compras, IVA), en PDF o en el cuerpo del mail, con el logo del estudio.',
                'parametros' => ['empresa' => ['type' => 'string'], 'email' => ['type' => 'string', 'description' => 'Si no lo dice, el último usado o el de la empresa.'], 'mes' => ['type' => 'string', 'description' => 'YYYY-MM; por defecto el mes pasado.'], 'formato' => ['type' => 'string', 'enum' => ['pdf', 'resumen']]], 'requeridos' => ['empresa'],
                'proponer' => function (User $u, array $a) {
                    $t = mb_strtolower($a['empresa']); $b = $u->empresasAccesibles()->first(fn($x) => str_contains(mb_strtolower($x->name), $t));
                    if (! $b) return ['error' => 'No encontré la empresa "' . $a['empresa'] . '" entre las que manejás.'];
                    $est = $u->empresasAccesibles()->first(fn($x) => $x->owner_id === $u->id) ?? $u->business;
                    $email = $a['email'] ?? (($est->estudio['mails'][$b->id] ?? null) ?: $b->email);
                    if (! $email) return ['error' => "No tengo el mail de {$b->name}. Decime a qué mail lo mando."];
                    $mes = $a['mes'] ?? now()->subMonth()->format('Y-m'); $f = $a['formato'] ?? 'pdf';
                    return ['titulo' => "Mandar a {$b->name} el resumen de {$mes}", 'detalle' => ($f === 'pdf' ? 'PDF adjunto' : 'Resumen en el mail') . " a {$email}", 'datos' => ['id' => $b->id, 'email' => $email, 'mes' => $mes, 'formato' => $f]];
                },
                'confirmar' => function (User $u, array $d) {
                    $req = \Illuminate\Http\Request::create('/', 'POST', ['email' => $d['email'], 'mes' => $d['mes'], 'formato' => $d['formato']]); $req->setUserResolver(fn() => $u);
                    app(\App\Http\Controllers\Contable\MisEmpresasController::class)->enviar($req, (int) $d['id']);
                    return ['texto' => "Listo, le mandé el resumen a {$d['email']}.", 'url' => Busca::url('/contador/empresas')];
                }],
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
