# Producto: ayuda y tour, contador multiempresa, API documentada y métricas de uso

Qué agrega la Fase 22 y dónde está cada cosa.

## Centro de ayuda y ayuda contextual

- Los artículos viven en `docs/ayuda/*.md` con un encabezado: `titulo`, `modulo`, `rutas` (prefijos de URL a los que aplica), `orden`, `resumen`. Se cachean por fecha de modificación de la carpeta; agregar un archivo alcanza para que aparezca.
- **/ayuda** lista y busca (título, resumen y cuerpo, con raíz de palabra: "anular" encuentra "anula"). **/ayuda/{slug}** muestra el artículo con sus secciones. **/ayuda/guia/{nombre}** renderiza las guías técnicas de `docs/` (ARCA, asistente, atajos, mercado argentino, seguridad, API).
- El botón **?** del encabezado abre el panel lateral con la guía de la pantalla actual (`/ayuda/contexto?ruta=`), búsqueda en vivo, acceso al centro, al tour y a soporte.
- El buscador global (Ctrl+K) incluye "Centro de ayuda" y "Soporte".

## Tour guiado

- Pasos definidos en `AyudaService::tour()`: cada uno apunta a un `data-tour="…"` del layout (menú, sucursal, buscar, alertas, ayuda, asistente) con título y texto.
- Se muestra una vez por usuario al entrar a Inicio o Mi negocio (`users.tour_visto_en`); al terminar o saltar se marca visto (`POST /ayuda/tour-visto`). "Volver a ver el tour" en el centro de ayuda lo reinicia; "Ver el tour" en el panel lo lanza en la pantalla actual.
- El componente `Tour.vue` oscurece la pantalla, recorta el elemento y ubica el globo; los pasos cuyo elemento no está visible se saltan.

## Contador con varias empresas

- Tabla `user_businesses` (usuario, empresa, rol en esa empresa). `User::empresasAccesibles()` devuelve la propia más las asignadas; `cambiarEmpresa()` cambia `business_id`, toma el rol del vínculo y la sucursal por defecto. La empresa de origen entra al listado con el rol actual, así al volver se recupera.
- **Contable → Contador → Dar acceso** con un email que ya existe: no crea otro usuario, lo vincula con el rol Contador de esta empresa. "Quitar" saca el vínculo. La lista marca a los externos como "estudio · N empresas".
- El encabezado muestra el selector **Cambiar empresa** cuando hay más de una (`POST /empresa/{id}`, auditado como `cambio_empresa`). Al iniciar sesión, un usuario con varias empresas cae en **Mis empresas** (`/contador/empresas`): por empresa, ventas y compras del mes, IVA débito/crédito/saldo, comprobantes sin asiento, pendientes de CAE, última copia, y accesos directos al libro IVA, exportación y fiscal.
- El aislamiento por empresa sigue siendo el `business_id` del usuario: al cambiar de empresa cambia todo lo que ve.

## API documentada

- `App\Support\ApiDocs` lee las rutas reales de `routes/api.php` y arma la referencia: grupo, método, ruta, resumen (según la acción del controlador), parámetros de ruta, si es pública o Bearer y su límite.
- **/api/docs**: página HTML con la guía de uso (`docs/api.md`: autenticación, convenciones, ejemplos curl, webhooks, pedidos entrantes) y los endpoints por grupo. **/api/openapi.json**: OpenAPI 3.0.3 para importar en Postman, Insomnia o Swagger UI. Ambas públicas y sin datos.
- Desde Configuración → Seguridad y API hay un botón a la documentación.

## Métricas de uso

- Middleware `RegistrarUso` (después de responder): cuenta una **vista** por GET o una **acción** por POST/PUT/DELETE, por usuario, módulo y día, en `uso_diario` (un UPDATE por pedido; INSERT sólo la primera vez en el día). Se ignoran búsquedas, el asistente, alertas, ayuda y las consultas automáticas del POS.
- `UsoService::empresa()` (módulos, usuarios activos, días con uso, serie diaria), `porUsuario()` y `global()` (empresas activas, en riesgo por días sin uso, ranking, módulos, serie).
- **Configuración → Usuarios**: columna "Actividad 30 días" (días y acciones) por usuario.
- **Panel BigSys → Uso** (`/admin/uso`): KPIs, empresas activas por día, en riesgo (7+ días sin uso, con estado), módulos más usados y ranking. **Empresa → pestaña Uso**: lo mismo para una empresa, con detalle por usuario.

## Pruebas

`tests/Feature/ProductoTest.php`: centro de ayuda (lista, artículo, búsqueda, contexto por ruta, guías, rutas inválidas), tour (una vez por usuario, reinicio), contador multiempresa (alta por email existente, listado, cambio de empresa con aislamiento y rol, vuelta a la de origen, empresa ajena prohibida, redirección al entrar, quitar acceso), API (página y OpenAPI desde rutas reales, seguridad por endpoint) y uso (registro por módulo, exclusiones, resumen, pantalla de usuarios, panel BigSys y detalle por empresa).
