---
titulo: Sueldos
modulo: sueldos
rutas: /sueldos
orden: 7
resumen: Legajos, liquidación mensual y aguinaldo, recibos, anticipos, pago desde fondos, cargas sociales, libro y F.931.
---

## Empleados

**Sueldos → Empleados**: legajo, CUIL, documento, categoría y convenio, fecha de ingreso, sueldo básico, **jornada** (completa, 3/4, media o 1/3), centro de costo, lugar de trabajo, modalidad (mensual o jornal), obra social y CBU para el pago.

Abajo de la ficha están los **conceptos propios del empleado**: marcá los que se le liquidan solo a él (por ejemplo el seguro asistencial CEC de Comercio) y, si hace falta, un valor propio que reemplaza al general.

## Conceptos

Vienen los básicos (antigüedad, presentismo, aportes de jubilación, obra social y PAMI, contribuciones patronales). Cada concepto se calcula de una de tres formas:

- **% de una base**: por ejemplo jubilación 11 % de `REM`.
- **Importe fijo**: por ejemplo seguro de vida; puede ser proporcional a la jornada.
- **Base ÷ valor × cantidad**: por ejemplo días normales = `BASICO` ÷ 30 × días, o vacaciones = `BASICO` ÷ 25 × días.

La base admite `BASICO`, `REM` (remunerativo), `NOREM` (no remunerativo) o códigos de otros conceptos sumados con `+` (ej. `REM+1238`). Con **Más** ves las opciones finas: multiplicar por los años de antigüedad, sumar un % por año, llevar la base a jornada completa (obra social), restar la detracción (contribuciones), grupo para el costo y "solo empleados asignados".

## Plantilla Comercio (CCT 130/75)

**Conceptos → Cargar Comercio** deja listos los 25 conceptos como los liquida un estudio: días normales, feriados y vacaciones, antigüedad 1 % por año, presentismo, sumas no remunerativas del acuerdo (1200 y 1238, prorrateadas por jornada), jubilación, INSSJP, obra social y ANSSAL, sindicato, CEC, FAECYS, y todas las contribuciones patronales (SIJP, INSSJP, asignaciones familiares, FNE, ART, obra social, seguro de vida y contribución OSECAC). También activa el redondeo del neto al peso.

Las sumas del acuerdo y los básicos cambian con cada paritaria: actualizalos en Conceptos y en la ficha de cada empleado.

## Liquidar

**Liquidar**: elegís período y tipo (mensual, SAC), cargás las novedades de cada empleado (días trabajados, feriados no trabajados y días de vacaciones; los días normales se ajustan solos a 30 − feriados − vacaciones; horas extra al 50% y 100%, adicionales, no remunerativos, anticipos ya pagados) y el sistema calcula bruto, deducciones, neto y contribuciones. Revisás y **Confirmar y contabilizar**: se hace el asiento de sueldos y cargas.

Si el contador liquida afuera, **Importar** su planilla (CSV) y el sistema hace recibos, asiento y pago igual.

## Recibos, pago y cargas

- **Recibos**: el recibo legal (ley 20.744, art. 140) con los datos del empleador, legajo, CUIL, documento, categoría, jornada, ingreso y antigüedad, cada concepto con código, cantidad e importe (remunerativo o no), totales, neto en letras, el **último depósito de aportes** y el costo total del empleador por grupo. Sale con el logo y los colores de la empresa. Se imprimen todos o de a uno.
- **Último depósito**: en la liquidación cargás fecha, banco y mes del último depósito de aportes; sale en cada recibo.
- **Configuración**: día de pago, actividad, convenio y obra social que se imprimen, banco de depósito, detracción y redondeo del neto.
- **Pagar**: desde la cuenta bancaria o la caja; un movimiento por empleado con su CBU.
- **Pagar cargas**: aportes y contribuciones del período al vencimiento.
- **Anticipos**: se registran cuando se pagan y se descuentan solos en la próxima liquidación.

## Para el contador

**Libro CSV** (libro de sueldos) y **F.931**: resumen por empleado con remuneración, no remunerativo, aportes, contribuciones y neto, listo para cargar en SICOSS o Libro de Sueldos Digital.
