<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\User;
use App\Services\IA\PantallaService;

// Fase 119.1: Bigcito lleva al usuario a donde pida («abrime la ficha de López», «llevame a cerrar caja», «imprimilo»).
// Son consultas (no cambian nada): devuelven la dirección y el panel navega solo; por WhatsApp o MCP queda el enlace.
class Navegacion
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'abrir_pantalla', 'titulo' => 'Abrir una pantalla', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Lleva al usuario a una pantalla del sistema por su nombre: «clientes», «cerrar caja», «pedidos web», «libros IVA», «nueva factura», «nuevo presupuesto», «nueva orden de compra». Con cliente abre la factura nueva ya con ese cliente elegido. Usala cuando pida abrir, ir, llevar o mostrar una pantalla. Sólo abre pantallas que el usuario tiene permitidas.',
                'parametros' => ['pantalla' => ['type' => 'string', 'description' => 'Nombre de la pantalla como lo dijo el usuario.'], 'cliente' => ['type' => 'string', 'description' => 'Opcional, para «nueva factura/presupuesto/remito»: el cliente a precargar (nombre, CUIT o id).']],
                'requeridos' => ['pantalla'],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(PantallaService::class);
                    [$p, $otras] = $svc->buscarPantalla($u, (string) $a['pantalla']);
                    if (! $p) return ['error' => 'No encontré una pantalla que se llame así' . ($otras ? '. ¿Será alguna de estas?: ' . implode(', ', $otras) : '') . '.'];
                    $ruta = $p['ruta'];
                    if (! empty($a['cliente']) && str_starts_with($ruta, '/comprobantes/nuevo') && ($c = Busca::cliente($a['cliente']))) $ruta .= (str_contains($ruta, '?') ? '&' : '?') . 'contact_id=' . $c->id;
                    return ['abierta' => trim(($p['grupo'] && $p['grupo'] !== 'Atajos' ? $p['grupo'] . ' → ' : '') . $p['label']), 'url' => Busca::url($ruta), 'parecidas' => $otras, '_navegar' => $ruta];
                }],
            ['nombre' => 'abrir_ficha', 'titulo' => 'Abrir una ficha', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Abre la ficha de un cliente, proveedor, artículo, comprobante de venta (factura, presupuesto, remito, nota), factura de compra, orden de compra u orden de servicio. Buscar acepta nombre, CUIT, código, número («0001-00000123», «123») o id; para comprobante o compra, el nombre del cliente o proveedor abre el último.',
                'parametros' => ['tipo' => ['type' => 'string', 'enum' => ['cliente', 'proveedor', 'articulo', 'comprobante', 'compra', 'orden_compra', 'orden_servicio'], 'description' => 'Qué ficha abrir.'], 'buscar' => ['type' => 'string', 'description' => 'Nombre, número, código o id.']],
                'requeridos' => ['tipo', 'buscar'],
                'ejecutar' => function (User $u, array $a) {
                    $f = app(PantallaService::class)->ficha($u, (string) $a['tipo'], (string) $a['buscar']);
                    if (! $f) return ['error' => "No encontré {$a['tipo']} «{$a['buscar']}» (o tu usuario no puede verlo)."];
                    return ['abierta' => $f['titulo'], 'url' => Busca::url($f['ruta']), '_navegar' => $f['ruta']];
                }],
            ['nombre' => 'imprimir', 'titulo' => 'Imprimir un comprobante', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Abre para imprimir (o guardar en PDF) un comprobante de venta o una orden de compra. Si el usuario dice «imprimilo» y está viendo un comprobante, pasá el id que figura en «Lo que está viendo».',
                'parametros' => ['tipo' => ['type' => 'string', 'enum' => ['comprobante', 'orden_compra'], 'description' => 'Por defecto comprobante.'], 'buscar' => ['type' => 'string', 'description' => 'Número, id, o nombre del cliente (imprime el último).']],
                'requeridos' => ['buscar'],
                'ejecutar' => function (User $u, array $a) {
                    $f = app(PantallaService::class)->ficha($u, (string) ($a['tipo'] ?? 'comprobante'), (string) $a['buscar']);
                    if (! $f || empty($f['imprimir'])) return ['error' => "No encontré qué imprimir con «{$a['buscar']}»."];
                    return ['para_imprimir' => $f['titulo'], 'url' => Busca::url($f['imprimir']), '_abrir' => $f['imprimir']];
                }],
        ];
    }
}
