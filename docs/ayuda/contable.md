---
titulo: Contabilidad y el contador
modulo: contable
rutas: /contable, /contador
orden: 7
resumen: Asientos automáticos, libros de IVA, retenciones, exportación al sistema del contador y acceso para el estudio.
---

## Todo se asienta solo

Cada factura, cobro, compra, pago, sueldo y movimiento de fondos genera su asiento en el plan de cuentas. No hay que "pasar" nada. **Contable → Diario, Mayor y Balance** muestran el resultado, y **Estadísticas → Rentabilidad** lo cruza con costos fijos y variables.

## Libros de IVA y fiscal

- **Libro IVA ventas y compras** por período, con exportación al **Libro IVA Digital** (zip para subir a ARCA).
- **Retenciones y percepciones**: certificados emitidos, archivos SICORE y SIRCAR, percepciones de IIBB, IVA y Ganancias.
- **Cruce con "Mis Comprobantes"** de ARCA: subís el archivo y el sistema marca lo que falta de un lado o del otro.

## Panel del contador

**Contable → Contador** junta lo que el estudio pide cada mes: checklist, exportación de asientos a **Tango, Holistor, Bejerman** o CSV, y el alta del contador como usuario con rol de sólo lectura contable.

Si el contador ya usa BigSysWeb con otro cliente, se le da acceso con el **mismo email**: le aparece un selector para cambiar de empresa y una pantalla **Mis empresas** con el IVA del mes, comprobantes sin asiento y pendientes de cada una.

## Cierre de ejercicio

**Contable → Ejercicio**: ajuste por inflación con el índice IPC (se actualiza desde INDEC), asiento de cierre y apertura del ejercicio siguiente. El mes de cierre se configura en Impuestos.

## Errores comunes

- **Un comprobante sin asiento**: aparece en el panel del contador; "Regenerar asientos" del período lo resuelve.
- **El balance no cierra**: cada asiento balancea por construcción; si ves diferencia, hay un asiento manual desbalanceado en borrador.
