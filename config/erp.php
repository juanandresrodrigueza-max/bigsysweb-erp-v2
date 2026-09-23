<?php

// Catálogo de módulos del ERP: alimenta el sidebar, el editor de roles y el control por plan.
return [

    'acciones' => ['ver', 'crear', 'editar', 'anular', 'exportar'],

    'modulos' => [
        'dashboard'     => ['label' => 'Inicio',         'icono' => 'home',      'grupo' => null,        'ruta' => '/dashboard',     'core' => true,  'disponible' => true],
        'comprobantes'  => ['label' => 'Comprobantes',   'icono' => 'receipt',   'grupo' => 'Comercial', 'ruta' => '/comprobantes',  'core' => true,  'disponible' => true],
        'clientes'      => ['label' => 'Clientes',       'icono' => 'users',     'grupo' => 'Comercial', 'ruta' => '/clientes',      'core' => true,  'disponible' => true],
        'proveedores'   => ['label' => 'Proveedores',    'icono' => 'truck',     'grupo' => 'Compras',   'ruta' => '/proveedores',   'core' => true,  'disponible' => true],
        'stock'         => ['label' => 'Stock',          'icono' => 'boxes',     'grupo' => 'Operación', 'ruta' => '/stock',         'core' => true,  'disponible' => true],
        'produccion'    => ['label' => 'Producción',     'icono' => 'factory',   'grupo' => 'Operación', 'ruta' => '/produccion',    'core' => false, 'disponible' => true],
        'fondos'        => ['label' => 'Fondos',         'icono' => 'wallet',    'grupo' => 'Finanzas',  'ruta' => '/fondos',        'core' => true,  'disponible' => true],
        'contable'      => ['label' => 'Contable',       'icono' => 'book',      'grupo' => 'Finanzas',  'ruta' => '/contable',      'core' => false, 'disponible' => true],
        'estadisticas'  => ['label' => 'Estadísticas',   'icono' => 'chart',     'grupo' => 'Finanzas',  'ruta' => '/estadisticas',  'core' => true,  'disponible' => true],
        'gastronomia'   => ['label' => 'Gastronomía',    'icono' => 'utensils',  'grupo' => 'Verticales','ruta' => '/gastronomia',   'core' => false, 'disponible' => false],
        'retail'        => ['label' => 'Comercio',       'icono' => 'store',     'grupo' => 'Verticales','ruta' => '/retail',        'core' => false, 'disponible' => false],
        'minimarket'    => ['label' => 'Minimarket',     'icono' => 'basket',    'grupo' => 'Verticales','ruta' => '/minimarket',    'core' => false, 'disponible' => false],
        'alertas'       => ['label' => 'Alertas',        'icono' => 'bell',      'grupo' => 'Sistema',   'ruta' => '/alertas',       'core' => true,  'disponible' => true],
        'configuracion' => ['label' => 'Configuración',  'icono' => 'settings',  'grupo' => 'Sistema',   'ruta' => '/configuracion', 'core' => true,  'disponible' => true],
    ],

    // Roles del sistema: se crean para cada empresa nueva y el dueño puede ajustarlos.
    'roles_sistema' => [
        'dueno' => [
            'nombre' => 'Dueño',
            'descripcion' => 'Acceso total, todas las sucursales, plan y suscripción.',
            'permisos' => '*',
        ],
        'administrador' => [
            'nombre' => 'Administrador',
            'descripcion' => 'Todo salvo plan, suscripción y borrar la empresa.',
            'permisos' => ['*' => ['ver', 'crear', 'editar', 'anular', 'exportar'], 'configuracion' => ['ver', 'editar']],
        ],
        'contador' => [
            'nombre' => 'Contador',
            'descripcion' => 'Contable, fondos y estadísticas; comprobantes solo lectura.',
            'permisos' => [
                'dashboard' => ['ver'], 'comprobantes' => ['ver', 'exportar'], 'clientes' => ['ver', 'exportar'],
                'proveedores' => ['ver', 'exportar'], 'fondos' => ['ver', 'crear', 'editar', 'exportar'],
                'contable' => ['ver', 'crear', 'editar', 'anular', 'exportar'], 'estadisticas' => ['ver', 'exportar'], 'alertas' => ['ver'],
            ],
        ],
        'vendedor' => [
            'nombre' => 'Vendedor',
            'descripcion' => 'Comprobantes de venta, clientes y consulta de stock.',
            'permisos' => ['dashboard' => ['ver'], 'comprobantes' => ['ver', 'crear', 'editar'], 'clientes' => ['ver', 'crear', 'editar'], 'stock' => ['ver'], 'alertas' => ['ver']],
        ],
        'cajero' => [
            'nombre' => 'Cajero',
            'descripcion' => 'Caja de su sucursal, cobros y cierre de turno.',
            'permisos' => ['dashboard' => ['ver'], 'fondos' => ['ver', 'crear'], 'comprobantes' => ['ver', 'crear'], 'alertas' => ['ver']],
        ],
        'compras' => [
            'nombre' => 'Compras',
            'descripcion' => 'Proveedores, órdenes de pago y stock.',
            'permisos' => ['dashboard' => ['ver'], 'proveedores' => ['ver', 'crear', 'editar', 'anular', 'exportar'], 'stock' => ['ver', 'crear', 'editar'], 'alertas' => ['ver']],
        ],
        'deposito' => [
            'nombre' => 'Depósito',
            'descripcion' => 'Stock, remitos y producción.',
            'permisos' => ['dashboard' => ['ver'], 'stock' => ['ver', 'crear', 'editar', 'exportar'], 'produccion' => ['ver', 'crear', 'editar'], 'alertas' => ['ver']],
        ],
        'mozo' => [
            'nombre' => 'Mozo',
            'descripcion' => 'Mesas y comandas de su sucursal.',
            'permisos' => ['gastronomia' => ['ver', 'crear', 'editar']],
        ],
        'cocina' => [
            'nombre' => 'Cocina',
            'descripcion' => 'Pantalla de cocina.',
            'permisos' => ['gastronomia' => ['ver', 'editar']],
        ],
    ],
];
