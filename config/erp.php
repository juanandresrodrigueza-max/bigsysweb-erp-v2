<?php

// Catálogo de módulos del ERP: alimenta el sidebar, el editor de roles y el control por plan.
return [

    'acciones' => ['ver', 'crear', 'editar', 'anular', 'exportar'],

    'modulos' => [
        'dashboard'     => ['label' => 'Inicio',         'icono' => 'home',      'grupo' => null,        'ruta' => '/dashboard',     'core' => true,  'disponible' => true],
        'comprobantes'  => ['label' => 'Ventas',         'icono' => 'receipt',   'grupo' => 'Vender y cobrar', 'ruta' => '/comprobantes',  'core' => true,  'disponible' => true],
        'clientes'      => ['label' => 'Clientes',       'icono' => 'users',     'grupo' => 'Vender y cobrar', 'ruta' => '/clientes',      'core' => true,  'disponible' => true],
        'proveedores'   => ['label' => 'Compras',        'icono' => 'basket',    'grupo' => 'Comprar',   'ruta' => '/proveedores',   'core' => true,  'disponible' => true],
        'stock'         => ['label' => 'Stock',          'icono' => 'boxes',     'grupo' => 'Stock', 'ruta' => '/stock',         'core' => true,  'disponible' => true],
        'agenda'        => ['label' => 'Agenda',         'icono' => 'calendar',  'grupo' => 'Mi rubro', 'ruta' => '/agenda',        'core' => false, 'disponible' => true],
        'obras'         => ['label' => 'Obras',          'icono' => 'hardhat',   'grupo' => 'Mi rubro', 'ruta' => '/obras',         'core' => false, 'disponible' => true],
        'produccion'    => ['label' => 'Producción',     'icono' => 'factory',   'grupo' => 'Mi rubro', 'ruta' => '/produccion',    'core' => false, 'disponible' => true],
        'fondos'        => ['label' => 'Plata',          'icono' => 'wallet',    'grupo' => 'Plata y contable',  'ruta' => '/fondos',        'core' => true,  'disponible' => true],
        'sueldos'       => ['label' => 'Sueldos',        'icono' => 'idcard',    'grupo' => 'Plata y contable',  'ruta' => '/sueldos',       'core' => false, 'disponible' => true],
        'contable'      => ['label' => 'Contable',       'icono' => 'book',      'grupo' => 'Plata y contable',  'ruta' => '/contable',      'core' => false, 'disponible' => true],
        'estadisticas'  => ['label' => 'Estadísticas',   'icono' => 'chart',     'grupo' => 'Estadísticas',  'ruta' => '/estadisticas',  'core' => true,  'disponible' => true],
        'gastronomia'   => ['label' => 'Big Restó',      'icono' => 'utensils',  'grupo' => 'Mi rubro','ruta' => '/gastronomia',   'core' => false, 'disponible' => true],
        'retail'        => ['label' => 'Comercio',       'icono' => 'store',     'grupo' => 'Mi rubro','ruta' => '/retail',        'core' => false, 'disponible' => true],
        'servicios'     => ['label' => 'Servicio técnico','icono' => 'wrench',   'grupo' => 'Mi rubro','ruta' => '/servicios',     'core' => false, 'disponible' => true],
        'hoteleria'     => ['label' => 'Hotelería',      'icono' => 'bed',       'grupo' => 'Mi rubro','ruta' => '/hoteleria',     'core' => false, 'disponible' => true],
        'minimarket'    => ['label' => 'Minimarket',     'icono' => 'basket',    'grupo' => 'Mi rubro','ruta' => '/minimarket',    'core' => false, 'disponible' => true],
        'alertas'       => ['label' => 'Alertas',        'icono' => 'bell',      'grupo' => 'Sistema',   'ruta' => '/alertas',       'core' => true,  'disponible' => true],
        'configuracion' => ['label' => 'Configuración',  'icono' => 'settings',  'grupo' => 'Sistema',   'ruta' => '/configuracion', 'core' => true,  'disponible' => true],
    ],

    // Roles del sistema: se crean para cada empresa nueva y el dueño puede ajustarlos.
    // Fase 79: el menú lateral, en el orden del día del negocio (vender → cobrar → entregar → comprar → stock → plata → contable → estadísticas).
    // Cada entrada apunta a una ruta y toma el permiso del módulo indicado; los permisos, los roles y la ayuda siguen usando 'modulos'.
    // 'grupo' sólo se muestra como título cuando hace falta (Mi rubro). Alertas no está: vive en la campana del encabezado.
    'menu' => [
        ['key' => 'inicio',       'label' => 'Inicio',           'icono' => 'home',     'ruta' => '/dashboard',               'modulo' => 'dashboard'],
        ['key' => 'vender',       'label' => 'Vender',           'icono' => 'receipt',  'ruta' => '/comprobantes',            'modulo' => 'comprobantes'],
        ['key' => 'cobrar',       'label' => 'Cobrar',           'icono' => 'cash',     'ruta' => '/clientes/cobranzas',      'modulo' => 'clientes'],
        ['key' => 'entregar',     'label' => 'Entregar',         'icono' => 'truck',    'ruta' => '/comprobantes/pendientes', 'modulo' => 'comprobantes'],
        ['key' => 'clientes',     'label' => 'Clientes',         'icono' => 'users',    'ruta' => '/clientes',                'modulo' => 'clientes'],
        ['key' => 'comprar',      'label' => 'Comprar',          'icono' => 'basket',   'ruta' => '/proveedores',             'modulo' => 'proveedores'],
        ['key' => 'stock',        'label' => 'Stock',            'icono' => 'boxes',    'ruta' => '/stock',                   'modulo' => 'stock'],
        ['key' => 'plata',        'label' => 'Plata',            'icono' => 'wallet',   'ruta' => '/fondos',                  'modulo' => 'fondos'],
        ['key' => 'contable',     'label' => 'Contable',         'icono' => 'book',     'ruta' => '/contable',                'modulo' => 'contable'],
        ['key' => 'sueldos',      'label' => 'Sueldos',          'icono' => 'idcard',   'ruta' => '/sueldos',                 'modulo' => 'sueldos'],
        ['key' => 'estadisticas', 'label' => 'Estadísticas',     'icono' => 'chart',    'ruta' => '/estadisticas',            'modulo' => 'estadisticas'],
        ['key' => 'gastronomia',  'label' => 'Big Restó',        'icono' => 'utensils', 'ruta' => '/gastronomia',             'modulo' => 'gastronomia', 'grupo' => 'Mi rubro'],
        ['key' => 'retail',       'label' => 'Comercio',         'icono' => 'store',    'ruta' => '/retail',                  'modulo' => 'retail',      'grupo' => 'Mi rubro'],
        ['key' => 'minimarket',   'label' => 'Minimarket',       'icono' => 'basket',   'ruta' => '/minimarket',              'modulo' => 'minimarket',  'grupo' => 'Mi rubro'],
        ['key' => 'servicios',    'label' => 'Servicio técnico', 'icono' => 'wrench',   'ruta' => '/servicios',               'modulo' => 'servicios',   'grupo' => 'Mi rubro'],
        ['key' => 'hoteleria',    'label' => 'Hotelería',        'icono' => 'bed',      'ruta' => '/hoteleria',               'modulo' => 'hoteleria',   'grupo' => 'Mi rubro'],
        ['key' => 'obras',        'label' => 'Obras',            'icono' => 'hardhat',  'ruta' => '/obras',                   'modulo' => 'obras',       'grupo' => 'Mi rubro'],
        ['key' => 'produccion',   'label' => 'Producción',       'icono' => 'factory',  'ruta' => '/produccion',              'modulo' => 'produccion',  'grupo' => 'Mi rubro'],
        ['key' => 'agenda',       'label' => 'Agenda',           'icono' => 'calendar', 'ruta' => '/agenda',                  'modulo' => 'agenda',      'grupo' => 'Mi rubro'],
        ['key' => 'configuracion','label' => 'Configuración',    'icono' => 'settings', 'ruta' => '/configuracion',           'modulo' => 'configuracion'],
    ],

    // Nombres viejos → nuevos, para que la ayuda y Bigcito entiendan los dos durante un tiempo.
    'nombres_viejos' => ['Comprobantes' => 'Vender', 'Fondos' => 'Plata', 'Proveedores' => 'Comprar'],

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
        // Fase 39.1: perfiles listos que tildan todo de una.
        'admin_ventas' => [
            'nombre' => 'Administrador de ventas',
            'descripcion' => 'Ventas, clientes y cobranzas completas, estadísticas de ventas; mira stock y caja.',
            'permisos' => ['dashboard' => ['ver'], 'comprobantes' => ['ver', 'crear', 'editar', 'anular', 'exportar'], 'clientes' => ['ver', 'crear', 'editar', 'anular', 'exportar'], 'estadisticas' => ['ver', 'exportar'], 'stock' => ['ver'], 'fondos' => ['ver', 'crear'], 'alertas' => ['ver']],
        ],
        'solo_mirar' => [
            'nombre' => 'Sólo mirar',
            'descripcion' => 'Ve todo y no puede cambiar nada (socios, auditores).',
            'permisos' => ['*' => ['ver']],
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
        'tecnico' => [
            'nombre' => 'Técnico',
            'descripcion' => 'Órdenes de trabajo, tareas y hojas de trabajo.',
            'permisos' => ['dashboard' => ['ver'], 'servicios' => ['ver', 'crear', 'editar'], 'stock' => ['ver'], 'alertas' => ['ver']],
        ],
        'recepcion' => [
            'nombre' => 'Recepción',
            'descripcion' => 'Reservas, check-in, consumos y check-out del hotel.',
            'permisos' => ['dashboard' => ['ver'], 'hoteleria' => ['ver', 'crear', 'editar'], 'comprobantes' => ['ver', 'crear'], 'alertas' => ['ver']],
        ],
        'deposito' => [
            'nombre' => 'Depósito',
            'descripcion' => 'Stock, remitos y producción.',
            'permisos' => ['dashboard' => ['ver'], 'stock' => ['ver', 'crear', 'editar', 'exportar'], 'produccion' => ['ver', 'crear', 'editar'], 'alertas' => ['ver']],
        ],
        // Fase 43.2: el chofer entra sólo a sus repartos (no ocupa un usuario del plan).
        'chofer' => [
            'nombre' => 'Chofer',
            'descripcion' => 'Ve sólo sus repartos desde el celular. No ocupa un usuario del plan.',
            'permisos' => ['dashboard' => ['ver']],
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
