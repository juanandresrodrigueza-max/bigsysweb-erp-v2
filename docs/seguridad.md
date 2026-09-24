# BigSysWeb ERP · Seguridad

Qué protege el sistema y qué hay que configurar al ponerlo en producción.

## Cuentas

- **Login con bloqueo**: 5 contraseñas incorrectas por email + IP bloquean 5 minutos. Cada fallo y cada bloqueo queda en Configuración → Auditoría (`login_fallido`, `login_bloqueado`).
- **Verificación en dos pasos (TOTP)** por usuario, con códigos de recuperación. El secreto se guarda cifrado.
- **Contraseñas**: mínimo 8 caracteres con letras y números, en alta de usuarios, cambio propio, recuperación y API.
- **Recuperación por mail** (`/recuperar`): link de un solo uso que vence a los 60 minutos; la respuesta no revela si el email existe. Requiere `MAIL_MAILER` configurado (con `log` el link queda en `storage/logs`).
- **Cambio de contraseña propio** en Configuración → Seguridad: pide la actual y cierra las demás sesiones.
- **Sesiones**: se listan y se pueden cerrar desde Seguridad. Sobre `https` la cookie va con `Secure`, siempre `HttpOnly` y `SameSite=Lax`.

## Datos de cada empresa

- Todo modelo con `business_id` usa el scope `BelongsToBusiness`: cada consulta queda limitada a la empresa del usuario. Los ids de otra empresa dan 404, no 403 (no se confirma que existan).
- Permisos por módulo y acción (`ver`, `crear`, `editar`, `anular`, `exportar`) en cada ruta; el dueño tiene todo, el resto según el rol.
- El superadmin entra a una empresa solo por "Entrar como", con registro en auditoría.
- Auditoría de altas, cambios, anulaciones, exportaciones, logins e impersonaciones, por empresa.

## Credenciales y secretos

- **Cifrado en la base** (clave de la aplicación `APP_KEY`): credenciales de Mercado Pago, Tiendanube y WhatsApp de la empresa, credenciales de canales (WooCommerce, Shopify, Mercado Libre, apps de delivery), secretos de 2FA. Migración `2026_10_09_000001_seguridad_cifrado` cifra lo existente.
- **Certificados AFIP** (certificado y clave privada) se guardan cifrados en disco (`.enc`) y se descifran a un archivo temporal 0600 solo al usarlos. Para instalaciones anteriores: `php artisan erp:cifrar-secretos`.
- Los tokens de API (Sanctum) se guardan hasheados; se muestran una sola vez.
- **Perder `APP_KEY` es perder estas credenciales**: guardarla junto con las copias de seguridad.

## Entradas del exterior

- Límites por IP: login y recuperación (10/min más el bloqueo), API de autenticación (10/min), páginas públicas (presupuestos, tienda, menú, reservas, portal, órdenes: 60/min), webhooks (300/min).
- Webhooks de Mercado Pago no confían en el cuerpo del aviso: consultan el pago a Mercado Pago con el token de la empresa y verifican el `external_reference` antes de acreditar.
- Archivos subidos: tipo y tamaño validados (CSV/XLSX para importaciones, PDF/XLSX/CSV para extractos, ZIP para restaurar, PEM para certificados, imágenes/PDF para adjuntos).
- Cabeceras en toda respuesta: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` y HSTS sobre https.

## Copias de seguridad

- Manuales y automáticas (diarias, en cola) por empresa: un ZIP con todas las tablas en JSON y CSV más los adjuntos. Restauración probada por la suite (se guarda una copia del estado previo antes de restaurar).
- Guardar los ZIP fuera del servidor (S3, disco externo). Contienen datos personales: tratarlos como confidenciales.

## Checklist de producción

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.tuempresa.com.ar
SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp
```

- HTTPS obligatorio (certificado válido); el ERP marca las cookies como seguras cuando `APP_URL` es https.
- `php artisan erp:cifrar-secretos` después de migrar una instalación anterior.
- Backups fuera del servidor y `APP_KEY` resguardada.
- Usuarios con rol mínimo necesario; 2FA para dueños y administradores.
