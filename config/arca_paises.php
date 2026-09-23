<?php

// Países de destino para factura de exportación (tabla FEXGetPARAM_DST_pais de ARCA) y CUIT país genérico de persona jurídica
// (tabla FEXGetPARAM_DST_CUIT). El CUIT país se puede pisar en la ficha del cliente si ARCA informa otro.
return [
    'paises' => [
        '202' => 'Bolivia', '203' => 'Brasil', '208' => 'Chile', '209' => 'Colombia', '210' => 'Costa Rica', '211' => 'Cuba', '212' => 'Estados Unidos', '213' => 'Ecuador', '215' => 'Guatemala',
        '218' => 'México', '219' => 'Nicaragua', '220' => 'Panamá', '221' => 'Paraguay', '222' => 'Perú', '223' => 'Puerto Rico', '225' => 'Uruguay', '226' => 'Venezuela', '204' => 'Canadá',
        '410' => 'España', '412' => 'Francia', '417' => 'Italia', '426' => 'Reino Unido', '438' => 'Alemania', '431' => 'Portugal', '425' => 'Países Bajos', '405' => 'Bélgica', '441' => 'Suiza',
        '315' => 'China', '320' => 'India', '325' => 'Japón', '326' => 'Israel', '333' => 'Corea del Sur', '346' => 'Emiratos Árabes', '501' => 'Australia', '503' => 'Nueva Zelanda', '374' => 'Sudáfrica',
    ],
    // CUIT país persona jurídica (55...) según la tabla de ARCA. Si falta, se carga a mano en el cliente.
    'cuit_pais' => [
        '203' => '55000002002', '208' => '55000003003', '225' => '55000005005', '221' => '55000004004', '202' => '55000001001', '222' => '55000006006', '212' => '55000012012', '218' => '55000009009',
    ],
    'incoterms' => ['FOB' => 'FOB · libre a bordo', 'CIF' => 'CIF · costo, seguro y flete', 'CFR' => 'CFR · costo y flete', 'EXW' => 'EXW · en fábrica', 'FCA' => 'FCA · franco transportista', 'DAP' => 'DAP · entregado en lugar', 'DDP' => 'DDP · entregado con derechos pagos', 'CPT' => 'CPT · transporte pagado hasta', 'CIP' => 'CIP · transporte y seguro pagados'],
    'tipos_expo' => [1 => 'Exportación de bienes', 2 => 'Exportación de servicios', 4 => 'Otros (locaciones, cesión de derechos)'],
    'monedas' => ['DOL' => 'Dólar estadounidense', 'PES' => 'Pesos argentinos', '060' => 'Euro', '012' => 'Real', '033' => 'Peso chileno', '011' => 'Peso uruguayo'],
];
