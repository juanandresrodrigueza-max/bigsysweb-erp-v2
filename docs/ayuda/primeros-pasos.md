---
titulo: Primeros pasos
modulo: dashboard
rutas: /dashboard, /primeros-pasos, /dueno
orden: 1
resumen: Qué configurar el primer día para empezar a facturar y cobrar, y cómo moverse por el sistema.
---

BigSysWeb se configura en una tarde. Este es el orden que recomendamos.

## 1. Datos de la empresa

En **Configuración → Empresa** cargá razón social, CUIT, condición frente al IVA y el logo. Todo eso sale impreso en las facturas. Si sos responsable inscripto, el sistema emite A o B según el cliente; si sos monotributista, emite C.

## 2. Punto de venta y ARCA

En **Configuración → Puntos de venta y AFIP** creá el punto de venta con el mismo número que tenés dado de alta en ARCA. Sin certificado, las facturas salen **simuladas** (sin CAE): sirven para probar. Cuando subas el certificado, salen con CAE real. La guía técnica está en *Ayuda → Guías → ARCA*.

## 3. Caja y banco

En **Fondos** tenés que tener al menos una caja (efectivo) y una cuenta bancaria. Cada cobro entra en una de ellas; sin cuentas no hay dónde registrar el dinero.

## 4. Artículos, clientes y proveedores

Cargalos a mano o importalos desde Excel en **Configuración → Importar datos**. Si venís de otro sistema, importá también los saldos de cuenta corriente: arrancás con las deudas al día.

## 5. Usuarios y roles

Un usuario por persona, cada uno con su rol (vendedor, cajero, depósito, contador…). Los roles definen qué pantallas ve cada uno y qué puede hacer. Se configuran en **Configuración → Usuarios** y **Roles y permisos**.

## 6. Primera factura

Hacé una de prueba desde **Comprobantes → Nueva factura**. Se puede anular después. Vas a ver cómo impacta en cuenta corriente, stock y contabilidad.

## Cómo moverse

- **Ctrl+K** abre el buscador de todo: clientes, artículos, comprobantes por número, pantallas.
- **Alt+N** factura nueva, **Alt+P** punto de venta, **Alt+C** clientes, **Alt+S** stock, **Alt+F** fondos.
- El **signo de pregunta** del encabezado abre la ayuda de la pantalla en la que estás.
- El **asistente** (botón violeta abajo a la derecha) responde preguntas y hace cosas: "¿cuánto me debe López?", "cobrale 5000 a Pérez en efectivo".
- En el celular, la barra de abajo lleva a **Mi negocio**, ventas, buscar y cobrar.

## Si algo no sale

Desde **Soporte** (menú) abrís un ticket y el equipo de BigSys te responde ahí y por mail. Podés también escribir por WhatsApp al número que figura en la pantalla de soporte.
