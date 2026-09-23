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

**Planes**: nombre, precio mensual y anual, usuarios y sucursales incluidos, módulos. **Cobros**: pagos por Mercado Pago (automáticos) o transferencia (se aprueban a mano). La revisión diaria avisa vencimientos, aplica gracia y suspende; se puede correr a mano desde Sistema.

## Entrar a una empresa

Desde la ficha de la empresa, **Entrar como dueño** para dar soporte. Todo lo que se haga queda auditado y marcado como hecho por el superadmin.

## Sistema

- Días de prueba y gracia, avisos.
- Credenciales de Mercado Pago de BigSys (para cobrar suscripciones), CBU y alias para transferencias.
- **Inteligencia artificial**: API key y modelo; sin clave, todo sigue en modo básico.
- **Correo saliente**: SMTP con prueba de envío.
- Mensaje global, contacto de soporte y **modo mantenimiento**.

## Uso y soporte

**Uso**: qué empresas usan el sistema, cuáles se están enfriando (7 y 14 días sin uso), ranking, módulos más usados y detalle por empresa y usuario. **Soporte**: los tickets de todas las empresas por prioridad; al responder, la empresa recibe una alerta.

## Antes de salir a producción

Ver `docs/produccion.md` (servidor, PostgreSQL, colas, cron, HTTPS, backups) y `docs/arca.md` (homologación de WSFE y WSFEX con certificado real).
