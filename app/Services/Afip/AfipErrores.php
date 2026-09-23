<?php

namespace App\Services\Afip;

// Traduce los errores típicos de ARCA/AFIP (WSFE, WSAA) a una explicación y qué hacer, para que el usuario no lea códigos.
class AfipErrores
{
    private const TABLA = [
        ['/10016|ya fue registrado|CbteDesde.*registrado/i', 'El número de comprobante ya existe en ARCA.', 'El sistema consulta el comprobante en ARCA y adopta su CAE. Si persiste, revisá que no haya otro sistema facturando en el mismo punto de venta.', 'duplicado'],
        ['/10015|CbteFch|fecha.*(fuera|anterior|posterior)/i', 'La fecha del comprobante está fuera del rango que acepta ARCA (hasta 5 días atrás para productos, 10 para servicios, y nunca posterior a hoy).', 'Corregí la fecha del comprobante y volvé a emitir.', 'fecha'],
        ['/10048|DocNro|CUIT.*(inválido|invalido|inexistente)|documento.*inválido/i', 'El CUIT/DNI del cliente no es válido para ARCA.', 'Verificá el número en la ficha del cliente (o consultalo en el padrón).', 'cliente'],
        ['/10192|10243|CondicionIVAReceptorId|condici[oó]n.*IVA.*receptor/i', 'ARCA exige informar la condición frente al IVA del receptor (RG 5616).', 'Revisá la condición de IVA del cliente en su ficha; para consumidor final sin CUIT el sistema manda "Consumidor Final".', 'cliente'],
        ['/10071|10063|ImpTotal|no coincide|suma de/i', 'Los importes informados no cierran (neto + IVA + tributos ≠ total).', 'Es un problema de redondeo: reabrí el comprobante como borrador y volvé a emitirlo; si sigue, avisanos.', 'importes'],
        ['/PtoVta|punto de venta.*(no|inv)/i', 'El punto de venta no está habilitado para factura electrónica en ARCA.', 'Dalo de alta en ARCA → Administración de puntos de venta y domicilios, como "Factura electrónica – Web Services".', 'punto_venta'],
        ['/CbtesAsoc|PeriodoAsoc|asociad/i', 'La nota de crédito/débito tiene que indicar el comprobante asociado.', 'Emitila desde la factura original (Comprobantes → factura → Nota de crédito).', 'asociado'],
        ['/ns1:coe\.alreadyAuthenticated|already.*authenticated|TA.*(vigente|válido)/i', 'Ya hay un ticket de acceso vigente con ARCA.', 'No hace falta hacer nada: reintentá en unos minutos.', 'wsaa'],
        ['/ns1:cms\.cert\.(expired|invalid)|certificado.*(venci|inv[aá]lid)|cert.*expired/i', 'El certificado digital está vencido o no es válido.', 'Generá uno nuevo en ARCA (Administrador de Relaciones → WSFE) y cargalo en Configuración → Puntos de venta.', 'certificado'],
        ['/no est[aá] autorizado|not authorized|computador.*no.*autorizado|ns1:cms\.cert\.untrusted/i', 'El certificado no está autorizado para el servicio de factura electrónica (WSFE) con este CUIT.', 'En ARCA, Administrador de Relaciones: asociá el certificado al servicio "Facturación Electrónica" para este CUIT.', 'autorizacion'],
        ['/(Could not connect|timed out|Connection refused|cURL|SOAP-ERROR|Failed to connect|wsaa|wsfe).*(host|resolve|connect|timeout)|Could not resolve host|error fetching http headers/i', 'No se pudo conectar con ARCA (caído o sin internet).', 'El comprobante queda pendiente de CAE y el sistema reintenta solo cada 5 minutos. Podés seguir trabajando.', 'conexion'],
        ['/600|601|token.*(inv[aá]lido|expir)|sign.*inv/i', 'El ticket de acceso a ARCA venció o es inválido.', 'Reintentá: el sistema pide uno nuevo. Si sigue, revisá la hora del servidor (tiene que estar en hora).', 'wsaa'],
        ['/1500|1501|error interno|internal/i', 'ARCA devolvió un error interno.', 'Esperá unos minutos y reintentá.', 'arca'],
    ];

    public static function explicar(string $mensaje): array
    {
        foreach (self::TABLA as [$re, $que, $como, $tipo]) {
            if (preg_match($re, $mensaje)) return ['tipo' => $tipo, 'que' => $que, 'como' => $como, 'original' => $mensaje];
        }
        return ['tipo' => 'otro', 'que' => 'ARCA rechazó el comprobante.', 'como' => 'Leé el detalle y corregí lo que indique; si no queda claro, avisanos con el texto del error.', 'original' => $mensaje];
    }

    // ¿Es un problema de conexión (contingencia) y no un rechazo de ARCA?
    public static function esConexion(string $mensaje): bool
    {
        return self::explicar($mensaje)['tipo'] === 'conexion' || preg_match('/SoapFault|SOAP|cURL|timed out|Could not connect|resolve host|http headers/i', $mensaje) === 1;
    }

    public static function esDuplicado(string $mensaje): bool
    {
        return self::explicar($mensaje)['tipo'] === 'duplicado';
    }
}
