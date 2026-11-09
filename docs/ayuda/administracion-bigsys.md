---
titulo: Panel de BigSys: alta de empresas, planes y sistema
modulo:
rutas: /admin
orden: 11
resumen: Para quien administra el servicio: dar de alta empresas, planes y cobros de suscripción, soporte, claves del sistema, métricas de uso y mantenimiento.
---

Esta guía es para el equipo que opera BigSysWeb como servicio (el superadmin), no para las empresas cliente.

## Alta de una empresa

**Panel → Empresas → Nueva empresa**: datos de la empresa, dueño (nombre, email y contraseña inicial), plan y modo (prueba de N días o activa con ciclo mensual o anual y medio de pago). Se crean la empresa, la sucursal, el punto de venta, la caja, el plan de cuentas y los roles. El dueño entra y ve "Primeros pasos" y la guía de implementación.

## Planes y cobros

**Planes**: nombre, precio mensual y anual, usuarios, sucursales, artículos y **facturas por mes** incluidos (-1 = sin límite), módulos. **Cobros**: pagos por Mercado Pago (automáticos) o transferencia (se aprueban a mano). La revisión diaria avisa vencimientos, aplica gracia y suspende; se puede correr a mano desde Sistema.

## Entrar a una empresa

Desde la ficha de la empresa, **Entrar como dueño** para dar soporte. Todo lo que se haga queda auditado y marcado como hecho por el superadmin.

## Sistema

- Días de prueba y gracia, avisos.
- Credenciales de Mercado Pago de BigSys (para cobrar suscripciones), CBU y alias para transferencias.
- **Inteligencia artificial**: API key y modelo; sin clave, todo sigue en modo básico.
- **Correo saliente**: SMTP con prueba de envío.
- Mensaje global, contacto de soporte y **modo mantenimiento**.

## Salud y monitoreo de errores (Panel → Salud)

Una pantalla con semáforo de todo lo que tiene que estar vivo en el servidor: base de datos, caché, cola de trabajos (worker), tareas programadas (cron), disco, copias de seguridad, comprobantes pendientes de CAE, cotización del dólar, correo, IA y errores de las últimas 24 horas. Cada control dice qué pasa y, al lado, qué hacer si está en rojo.

- **Errores**: cada error real del sistema (no los "página no encontrada" ni las validaciones) queda agrupado por lugar, con cuántas veces pasó, en qué empresa y la traza completa. Marcalos como resueltos cuando los arregles.
- **Sentry o compatible**: pegá el DSN de tu proyecto (Sentry, GlitchTip, Bugsink o cualquiera que hable el mismo protocolo) y los errores se mandan allá con la traza y la ruta, sin datos personales. **Enviar evento de prueba** confirma que llega. No hace falta instalar nada más.
- **Aviso por mail**: cargá un correo y, cuando un control pasa a crítico, llega un mail (una vez cada 6 horas por control). La revisión corre sola cada hora.
- **Uptime externo**: `https://tu-servidor/salud` responde 200 si está bien y 503 si hay algo crítico; ponelo en UptimeRobot, Better Stack o el monitor que uses. Con `?token=` (el token que cargues en la pantalla) devuelve el detalle de cada control.

## Enterarse al toque y la semana en un mail (Fase 76)

- **Error nuevo, aviso inmediato**: cuando aparece un error que nunca se había visto (o vuelve uno que marcaste resuelto), llega un mail al instante al mail de monitoreo (Panel → Salud), con la clase, dónde, la ruta, la empresa y un **código** de 8 letras. A lo sumo 10 por hora: si algo explota en cadena, el panel lo muestra entero.
- **La pantalla de error habla claro**: si a un usuario le falla algo, ve "Algo falló de nuestro lado", el mismo código, y un botón **Avisar a soporte** que abre un ticket con el error ya pegado (no tiene que explicar nada técnico). El ticket llega como "Algo no anda", prioridad alta.
- **Bigcito**: "¿cómo está el sistema?", "¿hubo errores hoy?" (`salud_del_sistema`, sólo superadmin): controles con problemas, errores de 24 h con sus códigos, empresas activas y en riesgo, copias atrasadas.
- **Resumen semanal**: los lunes a las 7:50 llega al mail de monitoreo cómo le fue al sistema la semana pasada: empresas que lo usaron y las que más, las en riesgo (sin uso 7 días o más), módulos más usados, errores con sus códigos, salud, copias y tickets. También a mano: `php artisan sistema:resumen-semanal`.

## Velocidad de las páginas (Fase 77)

Cada noche el sistema mide solo las pantallas clave (Inicio, Comprobantes, Clientes, Stock, Estadísticas, Fondos, Alertas) de las empresas más grandes, entrando como su dueño, y guarda cuánto tardó cada una y cuántas consultas hizo. En **Panel → Salud** está la tabla de la última noche con el peor tiempo, el promedio, la noche anterior y la consulta más lenta. Una página por encima del umbral pone la salud en aviso; dos noches seguidas, en crítico y avisa por mail. Así una pantalla que se va poniendo lenta con los datos se ve antes de que un cliente se queje.

## Copias que sirven (Fase 78)

En **Panel → Salud**, la tabla "Copias de seguridad por empresa" dice de cada una: cuándo fue la última copia, si **se abre y cuadra** (se verifica al generarla y de nuevo a las 3:40), si el **ensayo de restauración** del domingo anduvo (se restaura en una transacción que se deshace, sin tocar datos), y si está **fuera del servidor**. Dos controles de salud nuevos: "Copias verificadas" (crítico si una copia no abre, no cuadra o no se pudo restaurar) y "Copias fuera del servidor" (aviso mientras no haya disco externo; crítico si hay y una copia no subió). Para el disco externo: las variables `OFFSITE_S3_*` en el `.env` (las mismas que usa el CRM; si está en el mismo servidor se copian tal cual), y las copias se suben a `bigsysweb/erp/<empresa>/` en el mismo bucket. A mano: `php artisan backups:probar` y `php artisan backups:probar --ensayo`.

## Uso y soporte

**Uso**: qué empresas usan el sistema, cuáles se están enfriando (7 y 14 días sin uso), ranking, módulos más usados y detalle por empresa y usuario. **Soporte**: los tickets de todas las empresas por prioridad; al responder, la empresa recibe una alerta.

## Antes de salir a producción

Ver `docs/produccion.md` (servidor, PostgreSQL, colas, cron, HTTPS, backups) y `docs/arca.md` (homologación de WSFE y WSFEX con certificado real).
