<?php

namespace App\Support;

// Bancos de la Argentina (lista del BCRA, los de uso corriente) para cargar cheques desde el celular sin escribir el nombre.
class Bancos
{
    public const LISTA = [
        'Banco de la Nación Argentina', 'Banco de la Provincia de Buenos Aires', 'Banco Galicia', 'Banco Santander', 'BBVA', 'Banco Macro', 'Banco Credicoop', 'ICBC',
        'HSBC / Galicia Más', 'Banco Patagonia', 'Banco Ciudad', 'Banco Supervielle', 'Banco Itaú', 'Banco Comafi', 'Banco Hipotecario', 'Banco de Córdoba (Bancor)',
        'Banco de Santa Fe', 'Banco de Entre Ríos', 'Banco de San Juan', 'Banco de Corrientes', 'Banco del Chubut', 'Banco de La Pampa', 'Banco de Tierra del Fuego', 'Banco Provincia del Neuquén',
        'Banco de Santa Cruz', 'Banco Rioja', 'Banco de Formosa', 'Banco del Sol', 'Banco Industrial (BIND)', 'Banco Piano', 'Banco Mariva', 'Banco CMF', 'Banco Columbia', 'Banco Julio',
        'Banco Roela', 'Banco Dino', 'Banco Meridian', 'Banco de Servicios y Transacciones', 'Banco Voii', 'Brubank', 'Banco Bica', 'Banco Coinag', 'Banco de Inversión y Comercio Exterior (BICE)',
        'Banco Sáenz', 'Banco Masventas', 'Banco de Valores', 'Banco Municipal de Rosario', 'Nuevo Banco del Chaco', 'Banco Nuevo del Chaco', 'Otro',
    ];
}
