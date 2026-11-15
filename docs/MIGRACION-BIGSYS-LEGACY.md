# Migración BigSys legacy (Clarion, .tps) → ERP web

Estudio del 15/11/2026. Qué hay hoy, qué falta, y las dos vías para traer un cliente del BigSys de escritorio al ERP web sin planillas.

## 1. Lo que ya existe

| Pieza | Dónde | Qué hace |
|---|---|---|
| Lector de `.tps` | `big-connect/hub/agente/tps.js` (+ `keys.js`, `lib.js`) | Port a Node, sin dependencias, de tps-parse. Abre cualquier tabla de BigSys con su clave OWNER (las de la instalación están en `keys.js`). |
| Espejo SQLite | `big-connect/hub/agente/sync.js` | Lee los `.tps` de `dat/eNN` + `gen` y arma 50 tablas normalizadas (productos, clientes, cuenta_movimientos, fondos, cheques, plan_cuentas, pedidos, compras, pagos, factura_items, ventas por producto/cliente/vendedor…). |
| API de sólo lectura | Hub de BIG Connect, `/api/v1/tablas` y `/api/v1/tabla/<nombre>` | Clave `bk_…`, páginas de 5.000 filas. |
| Importador del ERP | `app/Services/Migracion/ImportadorService.php` | Entidades: clientes, proveedores, artículos, saldos de clientes y de proveedores (por comprobante). Perfil `bigsys` con los nombres de campo de Clarion. Actualiza sin duplicar (por `codigo`). |
| Migración por BIG Connect | `app/Services/Migracion/BigConnectService.php`, Configuración → Importar, Bigcito `migrar_desde_bigconnect` | Trae proveedores, clientes, artículos (6 listas netas, costo, IVA, stock, mínimo) y lo impago de cada comprobante. Pasada de prueba sin guardar; se repite el día del corte. |
| Referencias | `big-connect/REFERENCIA-TPS.md`, `REFERENCIA-BIGSYS.md`, `REFERENCIA-BIGSYS-CATALOGO.md` | Las 116 tablas campo por campo y de qué `.tps` sale cada dato. |

## 2. Qué NO se migra todavía (y a qué va en el ERP)

| BigSys | Tabla | ERP | Prioridad |
|---|---|---|---|
| Rubros y departamentos | `rub` | `rubros` (jerarquía rubro / departamento) | Alta: hoy el rubro entra como texto |
| Vendedores, cobradores, choferes | `ven` | usuarios o vendedores del ERP | Alta |
| Localidades y provincias | `loc`, `pro` | se resuelven al nombre al importar la cuenta | Hecho a medias |
| Empleados | `cta` TIPCTA='E' | `empleados` (legajo, CUIL, ingreso, básico) | Alta (fase 98 ya tiene el legajo completo) |
| Plan de cuentas | `imp` | `plan_cuentas` (mapeo a las cuentas del ERP) | Media: el ERP trae plan propio; conviene mapear, no copiar |
| Fondos: cajas, bancos, tarjetas | `fon` + saldo de `bal` | `cuentas_fondos` con saldo inicial | Alta |
| Cheques en cartera | `asi` con `tipval` CHQ/ECH y `movcon=0` | `cheques` | Alta |
| Precios por proveedor | `proveedor_precios` del espejo | `proveedor_articulo` | Media |
| Pedidos y órdenes pendientes | `ord` + `ite` | pedidos / órdenes de compra | Media |
| Historial de ventas por artículo | `his` | estadísticas (`ventas_producto_dia` ya viene resumido del espejo) | Media: sirve para «qué reponer» y rankings desde el día uno |
| Comprobantes históricos | `mov` + `lin` + `fac` | comprobantes como «Manual de talonario» (sin CAE), sólo los N últimos meses | Baja: sirve para libro IVA del año en curso y para ver la factura vieja desde la ficha del cliente |
| Sueldos: liquidaciones | `liq` + `sue` | `liquidaciones` importadas (como el CSV del contador) | Baja: alcanza con los últimos 12 meses para SAC y ganancias |
| Hotelería, créditos, taller | `hres`, `cre`, `veh`… | módulos del ERP | Según el cliente |

## 3. Las dos vías

### Vía A · Por BIG Connect (ya anda)

Para clientes que ya tienen BIG Connect instalado. Nada que construir para lo básico; sólo ampliar los pasos (tabla de arriba) usando las tablas que el Hub ya expone (`fondos`, `cheques`, `plan_cuentas`, `pedidos`, `pedido_items`, `proveedor_precios`, `ventas_producto_dia`, `compras`, `pagos`, `cuentas_pendientes`).

### Vía B · Subir la carpeta de BigSys (lo que falta construir)

Para el cliente que no tiene BIG Connect: comprime `C:\bigsys\dat\eNN` y `C:\bigsys\dat\gen` (son `.tps`, unos pocos cientos de MB como mucho) y lo sube en Configuración → Importar → **«Traer todo desde el BigSys de escritorio»**. El ERP lo lee él mismo:

1. `tools/tps/` en el repo del ERP: `tps.js`, `keys.js` y `lib.js` copiados verbatim de `big-connect/hub/agente/` (como hizo big-motor, anotando el commit de origen) más un `leer.js` de ~150 líneas que recibe la carpeta y escribe un JSON por tabla (art, rub, sto, cta, mov, ven, loc, pro, iva, fon, asi, imp, ord, ite, his). El servidor ya tiene Node (lo usa para `npm run build`; si no, el actualizador usa el contenedor `node:20-alpine`).
2. `LegacyBigSysService` en PHP: descomprime el zip en `storage/app/migraciones/{empresa}/`, corre `leer.js` con `Process`, y arma las mismas filas y mapeos que `BigConnectService::preparar()` reutilizando el importador. Mismo circuito: pasada de prueba, pasada real, repetible el día del corte.
3. Pasos nuevos del importador (sirven a las dos vías): `rubros`, `vendedores`, `empleados`, `fondos` (con saldo inicial y asiento de apertura), `cheques`, `precios_proveedor`, `pedidos_pendientes`, `historial_ventas`.
4. Control de cierre: después de migrar, un informe compara contra BigSys (cantidad de cuentas, de artículos, suma de saldos de clientes y proveedores, saldo de cada fondo, cheques en cartera, inventario valorizado) y marca las diferencias. Es lo que le da confianza al dueño para apagar el viejo.
5. Bigcito: «migrá la carpeta que subí», «¿qué diferencias quedaron?».

Riesgos conocidos: las claves OWNER pueden variar entre instalaciones (`keys.js` las tiene por barrido; el `leer.js` tiene que avisar «no pude abrir X» y no frenar todo); `mov` grande (años) se lee en streaming (`forEachDataRecord`), no en memoria; los precios de `art.PRELIS` pueden estar con IVA según el parámetro de la empresa (el espejo ya los netea; en la vía B hay que leer `par`).

## 4. Qué necesito para avanzar

- **Un juego de `.tps` de prueba** (una empresa de demo o una copia anonimizada de `dat/e01` + `gen`). Sin eso, la vía B se puede programar contra los tests de BIG Connect pero no probar de verdad. Se puede subir al Hub, o compartir una carpeta de tu máquina con Remote Control (hoy no hay ninguna carpeta compartida).
- Confirmar el orden: primero ampliar la vía A (rápido, un par de días) o ir directo a la vía B (una fase entera).
- Para la vía A en un cliente real: la URL del Hub y una clave `bk_` con acceso completo, cargadas en el formulario del ERP (nunca por chat).

## 5. Lo que no tengo

No tengo acceso a ningún BigSys instalado ni a tu máquina: sólo a los repos (`bigsysweb-erp-v2`, `big-connect`, `big-motor`, `bigsysweb-saas`) y a producción por HTTPS. Las referencias de BIG Connect alcanzan para programar la lectura y el mapeo; probarla contra datos reales depende del punto 4.
