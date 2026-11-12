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
        ['key' => 'sueldos',      'label' => 'Sueldos',          'icono' => 'idcard',   'ruta' => '/sueldos',                 'modulo' => 'sueldos', 'solo_sin' => 'contable'], // Fase 82: vive adentro de Contable; sola sólo para quien no ve Contable
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

    // Fase 80: la sub-navegación de cada entrada del menú (columna izquierda en la compu, chips arriba en el celular).
    // La clave es la de 'menu'; cada ítem toma el permiso del módulo de esa entrada salvo que diga 'modulo'/'accion'.
    // 'exacto' => la raíz sólo se marca con la URL justa (así /comprobantes/123 queda a pantalla completa, sin sub-menú).
    // 'grupo' arma títulos dentro de la columna (Fase 82 los usa en Configuración y Contable). Las fichas y formularios
    // (/nuevo, /nueva, /editar, /armar) van siempre a pantalla completa.
    'secciones_sin' => ['nuevo', 'nueva', 'editar', 'armar'],
    'secciones' => [
        // Fase 83: Mostrador y Configurar canales son 'solo_link' (se listan, pero no adueñan la URL: el mostrador va a pantalla completa
        // y /configuracion/tienda sigue siendo de Configuración). Tienda online vive acá aunque su URL siga en /stock.
        'vender' => [
            ['key' => 'mostrador',    'label' => 'Mostrador',            'ruta' => '/vender', 'solo_link' => true, 'accion' => 'crear', 'ayuda' => 'Cobrar y facturar en el momento'],
            ['key' => 'comprobantes', 'label' => 'Comprobantes',         'ruta' => '/comprobantes', 'exacto' => true, 'ayuda' => 'Facturas, notas, remitos y presupuestos'],
            ['key' => 'abonos',       'label' => 'Abonos',               'ruta' => '/comprobantes/abonos'],
            ['key' => 'lote',         'label' => 'Facturación por lote', 'ruta' => '/comprobantes/lote'],
            ['key' => 'pedidos',      'label' => 'Pedidos web',          'ruta' => '/comprobantes/pedidos', 'grupo' => 'Canales', 'palabras' => 'whatsapp mercadolibre delivery menú qr portal'],
            ['key' => 'tienda-online','label' => 'Tienda online',        'ruta' => '/stock/tienda-online', 'grupo' => 'Canales', 'modulo' => 'stock', 'palabras' => 'publicar catálogo'],
            ['key' => 'canales',      'label' => 'Configurar canales',   'ruta' => '/configuracion/tienda', 'grupo' => 'Canales', 'modulo' => 'configuracion', 'solo_link' => true],
        ],
        'cobrar' => [
            ['key' => 'cobranzas', 'label' => 'Cobranzas', 'ruta' => '/clientes/cobranzas', 'exacto' => true],
            ['key' => 'acopios',   'label' => 'Acopios',   'ruta' => '/clientes/acopios', 'ayuda' => 'Lo que el cliente pagó por adelantado y todavía no retiró'],
        ],
        'entregar' => [
            ['key' => 'pendientes', 'label' => 'Pendientes',             'ruta' => '/comprobantes/pendientes', 'ayuda' => 'Entregado sin facturar y facturado sin entregar'],
            ['key' => 'entregas',   'label' => 'Hojas de reparto',       'ruta' => '/comprobantes/entregas'],
            ['key' => 'pases',      'label' => 'Pases entre sucursales', 'ruta' => '/stock/pases', 'modulo' => 'stock'],
        ],
        'clientes' => [
            ['key' => 'clientes',   'label' => 'Clientes',   'ruta' => '/clientes', 'exacto' => true],
            ['key' => 'vendedores', 'label' => 'Vendedores', 'ruta' => '/clientes/vendedores'],
            ['key' => 'puntos',     'label' => 'Puntos',     'ruta' => '/clientes/fidelizacion'],
        ],
        'comprar' => [
            ['key' => 'reponer',     'label' => 'Qué reponer',        'ruta' => '/proveedores/reponer', 'ayuda' => 'Lo que está en el mínimo, por proveedor habitual'],
            ['key' => 'proveedores', 'label' => 'Proveedores',        'ruta' => '/proveedores', 'exacto' => true],
            ['key' => 'ordenes',     'label' => 'Órdenes de compra',  'ruta' => '/proveedores/ordenes'],
            ['key' => 'compras',     'label' => 'Facturas de compra', 'ruta' => '/proveedores/compras'],
            ['key' => 'pagos',       'label' => 'Pagos',              'ruta' => '/proveedores/pagos', 'palabras' => 'órdenes de pago'],
        ],
        'stock' => [
            ['key' => 'articulos',    'label' => 'Artículos',             'ruta' => '/stock', 'exacto' => true],
            ['key' => 'precios',      'label' => 'Precios y datos en masa','ruta' => '/stock/precios', 'accion' => 'editar'],
            ['key' => 'inventario',   'label' => 'Inventario',            'ruta' => '/stock/inventario', 'accion' => 'editar'],
            ['key' => 'movimientos',  'label' => 'Movimientos',           'ruta' => '/stock/movimientos'],
            ['key' => 'lotes',        'label' => 'Lotes y vencimientos',  'ruta' => '/stock/lotes'],
            ['key' => 'series',       'label' => 'Números de serie',      'ruta' => '/stock/series'],
            ['key' => 'almacen',      'label' => 'Almacén',               'ruta' => '/stock/almacen'],
            ['key' => 'etiquetas',    'label' => 'Etiquetas',             'ruta' => '/stock/etiquetas'],
            ['key' => 'informes',     'label' => 'Informes',              'ruta' => '/stock/informes'],
            ['key' => 'desc-lista',   'label' => 'Descuentos por lista',  'ruta' => '/stock/descuentos-lista', 'grupo' => 'De vez en cuando'],
            ['key' => 'catalogos',    'label' => 'Catálogos',             'ruta' => '/stock/catalogos',        'grupo' => 'De vez en cuando'],
            ['key' => 'importar',     'label' => 'Importar lista',        'ruta' => '/stock/importar',         'grupo' => 'De vez en cuando', 'accion' => 'editar'],
            ['key' => 'despiece',     'label' => 'Carnicería y pesables', 'ruta' => '/stock/despiece',         'grupo' => 'De vez en cuando'],
            ['key' => 'consultor',    'label' => 'Consultor de precios',  'ruta' => '/stock/consultor',        'grupo' => 'De vez en cuando', 'ayuda' => 'Precio y stock al toque, también para la pantalla del salón'],
        ],
        'plata' => [
            ['key' => 'cuentas',     'label' => 'Cajas y bancos',  'ruta' => '/fondos', 'exacto' => true],
            ['key' => 'cierres',     'label' => 'Cierres de caja', 'ruta' => '/fondos/cierres', 'ayuda' => 'Historial de cierres, diferencias por cajero y por caja'],
            ['key' => 'cheques',     'label' => 'Cheques',         'ruta' => '/fondos/cheques'],
            ['key' => 'valores',     'label' => 'Valores',         'ruta' => '/fondos/valores', 'ayuda' => 'De dónde vino y a dónde fue cada cheque o cupón'],
            ['key' => 'tarjetas',    'label' => 'Tarjetas',        'ruta' => '/fondos/tarjetas'],
            ['key' => 'conciliar',   'label' => 'Conciliar',       'ruta' => '/fondos/conciliar'],
            ['key' => 'dolares',     'label' => 'Dólares',         'ruta' => '/fondos/moneda', 'ayuda' => 'Cuentas en dólares, cotización y diferencia de cambio'],
            ['key' => 'previsiones', 'label' => 'Previsiones',     'ruta' => '/fondos/previsiones', 'ayuda' => 'Gastos e ingresos que se repiten todos los meses'],
        ],
        // Fase 82: Contable en tres grupos y Sueldos adentro (quien no ve Contable tiene la sección 'sueldos' sola).
        'contable' => [
            ['key' => 'resumen',      'label' => 'Resumen',               'ruta' => '/contable', 'exacto' => true, 'grupo' => 'Lo diario'],
            ['key' => 'asientos',     'label' => 'Asientos',              'ruta' => '/contable/asientos',     'grupo' => 'Lo diario', 'palabras' => 'libro diario'],
            ['key' => 'mayor',        'label' => 'Mayor',                 'ruta' => '/contable/mayor',        'grupo' => 'Lo diario'],
            ['key' => 'iva',          'label' => 'Libros IVA',            'ruta' => '/contable/iva',          'grupo' => 'Lo diario', 'palabras' => 'ventas compras'],
            ['key' => 'balance',      'label' => 'Balance',               'ruta' => '/contable/balance',      'grupo' => 'Para el contador', 'palabras' => 'resultado patrimonio'],
            ['key' => 'flujo',        'label' => 'Flujo de fondos',       'ruta' => '/contable/flujo',        'grupo' => 'Para el contador'],
            ['key' => 'cashflow',     'label' => 'Cash flow 13 semanas',  'ruta' => '/contable/cashflow',     'grupo' => 'Para el contador', 'palabras' => 'proyección'],
            ['key' => 'fiscal',       'label' => 'Fiscal',                'ruta' => '/contable/fiscal',       'grupo' => 'Para el contador', 'palabras' => 'retenciones percepciones libro digital'],
            ['key' => 'contador',     'label' => 'Informe mensual',       'ruta' => '/contable/contador',     'grupo' => 'Para el contador', 'palabras' => 'contador exportar'],
            ['key' => 'liquidaciones','label' => 'Liquidaciones',         'ruta' => '/sueldos', 'exacto' => true, 'grupo' => 'Sueldos', 'modulo' => 'sueldos', 'palabras' => 'empleados legajos'],
            ['key' => 'recibos',      'label' => 'Recibos',               'ruta' => '/sueldos/recibos',       'grupo' => 'Sueldos', 'modulo' => 'sueldos', 'palabras' => 'firmas'],
            ['key' => 'plan',         'label' => 'Plan de cuentas',       'ruta' => '/contable/plan',         'grupo' => 'Puesta a punto'],
            ['key' => 'ejercicio',    'label' => 'Ejercicio',             'ruta' => '/contable/ejercicio',    'grupo' => 'Puesta a punto', 'palabras' => 'cierre apertura'],
            ['key' => 'activos',      'label' => 'Bienes de uso',         'ruta' => '/contable/activos',      'grupo' => 'Puesta a punto', 'palabras' => 'amortizaciones'],
            ['key' => 'conciliacion', 'label' => 'Conciliación',          'ruta' => '/contable/conciliacion', 'grupo' => 'Puesta a punto', 'palabras' => 'banco extracto'],
        ],
        // Fase 83: "Mi rubro" es una sola entrada del menú; sus secciones son las pantallas de los verticales que la empresa tiene prendidos.
        // Los mostradores (Comercio, Minimarket) son 'solo_link': van a pantalla completa.
        'rubro' => [
            ['key' => 'mesas',       'label' => 'Mesas',            'ruta' => '/gastronomia', 'exacto' => true, 'modulo' => 'gastronomia', 'grupo' => 'Big Restó'],
            ['key' => 'cocina',      'label' => 'Cocina',           'ruta' => '/gastronomia/cocina',   'modulo' => 'gastronomia', 'grupo' => 'Big Restó'],
            ['key' => 'carta',       'label' => 'Carta',            'ruta' => '/gastronomia/carta',    'modulo' => 'gastronomia', 'grupo' => 'Big Restó'],
            ['key' => 'reservas',    'label' => 'Reservas',         'ruta' => '/gastronomia/reservas', 'modulo' => 'gastronomia', 'grupo' => 'Big Restó'],
            ['key' => 'llamador',    'label' => 'Llamador',         'ruta' => '/gastronomia/llamador', 'modulo' => 'gastronomia', 'grupo' => 'Big Restó'],
            ['key' => 'retail',      'label' => 'Mostrador',        'ruta' => '/retail',     'solo_link' => true, 'modulo' => 'retail',     'grupo' => 'Comercio'],
            ['key' => 'minimarket',  'label' => 'Caja',             'ruta' => '/minimarket', 'solo_link' => true, 'modulo' => 'minimarket', 'grupo' => 'Minimarket'],
            ['key' => 'servicios',   'label' => 'Órdenes',          'ruta' => '/servicios', 'exacto' => true, 'modulo' => 'servicios', 'grupo' => 'Servicio técnico'],
            ['key' => 'mis-ordenes', 'label' => 'Mis órdenes',      'ruta' => '/servicios/mis-ordenes', 'modulo' => 'servicios', 'grupo' => 'Servicio técnico'],
            ['key' => 'que-vuelvan', 'label' => 'Que vuelvan',      'ruta' => '/servicios/que-vuelvan', 'modulo' => 'servicios', 'grupo' => 'Servicio técnico'],
            ['key' => 'tecnicos',    'label' => 'Técnicos',         'ruta' => '/servicios/tecnicos',    'modulo' => 'servicios', 'grupo' => 'Servicio técnico'],
            ['key' => 'hoteleria',   'label' => 'Habitaciones',     'ruta' => '/hoteleria', 'modulo' => 'hoteleria', 'grupo' => 'Hotelería'],
            ['key' => 'obras',       'label' => 'Obras',            'ruta' => '/obras', 'exacto' => true, 'modulo' => 'obras', 'grupo' => 'Obras'],
            ['key' => 'produccion',  'label' => 'Órdenes',          'ruta' => '/produccion', 'exacto' => true, 'modulo' => 'produccion', 'grupo' => 'Producción'],
            ['key' => 'formulas',    'label' => 'Fórmulas',         'ruta' => '/produccion/formulas', 'modulo' => 'produccion', 'grupo' => 'Producción'],
            ['key' => 'agenda',      'label' => 'Agenda',           'ruta' => '/agenda', 'modulo' => 'agenda', 'grupo' => 'Agenda'],
        ],
        'sueldos' => [
            ['key' => 'liquidaciones', 'label' => 'Liquidaciones', 'ruta' => '/sueldos', 'exacto' => true],
            ['key' => 'recibos',       'label' => 'Recibos',       'ruta' => '/sueldos/recibos'],
        ],
        // Fase 82: Configuración en cuatro grupos; 'palabras' alimenta el buscador de la columna.
        'configuracion' => [
            ['key' => 'empresa',      'label' => 'Empresa',                 'ruta' => '/configuracion', 'exacto' => true, 'grupo' => 'Mi empresa', 'palabras' => 'logo cuit dirección datos mercado pago cartelera'],
            ['key' => 'sucursales',   'label' => 'Sucursales',              'ruta' => '/configuracion/sucursales',   'grupo' => 'Mi empresa', 'palabras' => 'depósitos casa central'],
            ['key' => 'usuarios',     'label' => 'Usuarios',                'ruta' => '/configuracion/usuarios',     'grupo' => 'Mi empresa', 'palabras' => 'contraseña invitar'],
            ['key' => 'roles',        'label' => 'Roles y permisos',        'ruta' => '/configuracion/roles',        'grupo' => 'Mi empresa', 'palabras' => 'qué puede hacer cada uno'],
            ['key' => 'puntos-venta', 'label' => 'Puntos de venta y AFIP',  'ruta' => '/configuracion/puntos-venta', 'grupo' => 'Ventas y fiscal', 'palabras' => 'arca certificado cae factura electrónica'],
            ['key' => 'impuestos',    'label' => 'Impuestos',               'ruta' => '/configuracion/impuestos',    'grupo' => 'Ventas y fiscal', 'palabras' => 'iva percepciones ingresos brutos'],
            ['key' => 'tienda',       'label' => 'Tienda y canales',        'ruta' => '/configuracion/tienda',       'grupo' => 'Integraciones', 'palabras' => 'tienda online mercadolibre woocommerce delivery pedidos web'],
            ['key' => 'crm',          'label' => 'CRM',                     'ruta' => '/configuracion/crm',          'grupo' => 'Integraciones', 'palabras' => 'vendedores oportunidades'],
            ['key' => 'conexiones-ia','label' => 'Conexiones IA',           'ruta' => '/conexiones-ia',              'grupo' => 'Integraciones', 'palabras' => 'claude chatgpt bigcito mcp token'],
            ['key' => 'seguridad',    'label' => 'Seguridad y API',         'ruta' => '/configuracion/seguridad',    'grupo' => 'Integraciones', 'palabras' => 'dos pasos 2fa api token sesiones'],
            ['key' => 'importar',     'label' => 'Importar datos',          'ruta' => '/configuracion/importar',     'grupo' => 'Datos y cuenta', 'palabras' => 'csv excel big connect migrar exportar'],
            ['key' => 'datos',        'label' => 'Copias de seguridad',     'ruta' => '/configuracion/datos',        'grupo' => 'Datos y cuenta', 'palabras' => 'backup respaldo descargar'],
            ['key' => 'auditoria',    'label' => 'Auditoría',               'ruta' => '/configuracion/auditoria',    'grupo' => 'Datos y cuenta', 'palabras' => 'quién hizo qué historial'],
            ['key' => 'suscripcion',  'label' => 'Suscripción',             'ruta' => '/suscripcion', 'exacto' => true, 'grupo' => 'Datos y cuenta', 'palabras' => 'plan pago factura bigsys'],
        ],
    ],

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
