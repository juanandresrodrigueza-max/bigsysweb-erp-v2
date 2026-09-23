# Cierre para producción (Fase 23)

Lo que se agregó para salir a producción y lo que queda del lado de quien opera el sistema.

## Claves desde el panel de BigSys

**Panel → Sistema**: API key y modelo de IA, y SMTP completo (servidor, puerto, usuario, contraseña, cifrado, remitente). Se guardan cifradas en `sistema_config`, no se vuelven a mostrar y tienen prioridad sobre el `.env` (`SistemaConfig::aplicar()` corre al arrancar). "Probar la clave" hace una consulta mínima a la IA; "Enviar prueba" manda un correo real. "Borrar clave del panel" vuelve al `.env`.

## Factura de exportación (WSFEX)

- Cliente con condición **Exterior**: país (tabla de ARCA en `config/arca_paises.php`), CUIT país (se completa solo para los países cargados; si ARCA informa otro, se corrige en la ficha) e identificación fiscal en su país.
- A un cliente del exterior le sale **Factura E** (tipo 19) y notas E (20 y 21), sin IVA. El bloque "Exportación" del formulario pide tipo (bienes, servicios, otros), incoterm, permiso de embarque, moneda para ARCA (DOL con la cotización de la factura, o PES), forma de pago y observaciones.
- `App\Services\Afip\Wsfex` arma el SOAP a mano (el SDK no trae WSFEX) con el token del mismo WSAA: `FEXGetLast_CMP`, `FEXGetLast_ID`, `FEXAuthorize`, `FEXGetCMP`. Los errores `FEXErr` llegan como mensaje. Contingencia (pendiente y reintento) y rechazo funcionan igual que en WSFE. "Verificar en ARCA" también consulta por WSFEX.
- Para homologar: el certificado tiene que tener autorizado el servicio `wsfex` además de `wsfe`.

## Tilde "Informar a ARCA"

En facturas y notas. Apagado, el comprobante sale como **interno**: numeración propia por tipo (no gasta números fiscales), sin CAE, impreso con "NO VÁLIDO COMO FACTURA", excluido del libro de IVA, del Libro IVA Digital, de percepciones, del cruce con "Mis Comprobantes" y del panel del contador (`scopeFiscales`). Sigue impactando cuenta corriente, stock y contabilidad. Las notas sobre un interno son internas.

## COT por web service de ARBA

Con la clave CIT cargada en **Configuración → Impuestos** (cifrada en `businesses.arba_settings`), el remito emitido tiene "Pedir COT a ARBA": sube el archivo al servicio (`presentarRemitos.do`, test o producción según el tilde) y guarda el código. Los errores por remito (patente inválida, etc.) se muestran tal cual. El archivo manual sigue disponible.

## Dictado por voz

`resources/js/util/dictado.js` usa el reconocimiento de voz del navegador (Chrome, Edge, Safari; español rioplatense). Está en el asistente (manda la consulta al terminar de hablar), en "Cargar con IA" de la factura y en el pedido por WhatsApp. No usa ninguna API paga.

## Importación con perfil de origen

**Configuración → Importar → Viene de**: Tango Gestión, Bejerman/Softland o Colppy. El perfil (`ImportadorService::PERFILES`) reconoce los encabezados que exporta cada sistema y arma el mapeo; lo que no reconoce cae en las reglas generales. Se puede corregir antes de importar.

## Sueldos: F.931

**Sueldos → F.931** baja el resumen por empleado del período (CUIL, remuneración, no remunerativo, aportes retenidos sin contar anticipos, contribuciones, neto, obra social) con totales, para cargar en SICOSS o el Libro de Sueldos Digital. Los recibos ya salían desde "Recibos".

## Pruebas de navegador en CI

`tests/e2e/*.mjs` (Playwright, con `lib.mjs` para login, capturas y errores) corren en GitHub Actions (job `e2e` en `.github/workflows/ci.yml`) contra un servidor con la base demo recién sembrada. Las capturas quedan como artefacto del workflow. Local:

```
php artisan migrate:fresh --seed && php artisan serve &
CHROMIUM_PATH=/ruta/a/chromium node tests/e2e/run.mjs
```

## Queda del lado del operador

- Homologar contra ARCA con certificado real (WSFE y WSFEX) y pedir el pase a producción.
- Montar el servidor (PostgreSQL, worker de colas, cron, HTTPS, backups fuera del servidor): `docs/produccion.md`.
- Cargar credenciales reales de Mercado Pago, Mercado Libre y ARBA en cada empresa y probarlas.
