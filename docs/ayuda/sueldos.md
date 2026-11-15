---
titulo: Sueldos
modulo: sueldos
rutas: /sueldos
orden: 7
resumen: Legajos completos, ausencias y vacaciones (LCT), feriados, liquidación mensual y aguinaldo, recibos, anticipos, pago desde fondos, cargas sociales, F.931, Libro de Sueldos Digital y ganancias 4ta.
---

## Empleados

**Sueldos → Empleados**: legajo, CUIL, documento, categoría y convenio, fecha de ingreso, sueldo básico, **jornada** (completa, 3/4, media o 1/3), centro de costo, lugar de trabajo, modalidad (mensual o jornal), obra social y CBU para el pago.

**Más datos del legajo** (fase 98): fecha de nacimiento, estado civil, domicilio y localidad, hijos, contacto de emergencia y el **ajuste de vacaciones** (días a favor o adeudados que trae de años anteriores). **Para el Libro de Sueldos Digital y ganancias**: situación de revista, condición, modalidad de contratación, adherentes a la obra social y las deducciones del SIRADIG (cónyuge, hijos, alquiler, servicio doméstico, otras).

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

### Las novedades contadas en castellano (Fase 65)

Arriba de la tabla de **Liquidar** hay un recuadro: escribís o **dictás** las novedades como te salgan ("Juan hizo 6 horas extra al 50 y faltó 2 días; María 7 días de vacaciones y premio 20.000; Pedro no se liquida") y **Completar la tabla** las reparte en cada empleado: días (30 menos faltas, feriados y vacaciones), feriados, vacaciones, horas al 50 y al 100, premios, no remunerativos, anticipos y a quién excluir. Lo que no entendió te lo dice. Con clave de IA entiende apodos y frases sueltas ("la Mari se tomó dos semanas"); sin clave, por palabras. Siempre revisás la tabla antes de calcular.

A Bigcito: "liquidá octubre: Juan faltó un día e hizo 4 extras, María de vacaciones dos semanas" → te muestra cómo quedó cada uno y, cuando confirmás, deja la liquidación en borrador.

## Recibos, pago y cargas

- **Recibos**: el recibo legal (ley 20.744, art. 140) con los datos del empleador, legajo, CUIL, documento, categoría, jornada, ingreso y antigüedad, cada concepto con código, cantidad e importe (remunerativo o no), totales, neto en letras, el **último depósito de aportes** y el costo total del empleador por grupo. Sale con el logo y los colores de la empresa. Se imprimen todos o de a uno.
- **Último depósito**: en la liquidación cargás fecha, banco y mes del último depósito de aportes; sale en cada recibo.
- **Configuración**: día de pago, actividad, convenio y obra social que se imprimen, banco de depósito, detracción y redondeo del neto.
- **Pagar**: desde la cuenta bancaria o la caja; un movimiento por empleado con su CBU.
- **Pagar cargas**: aportes y contribuciones del período al vencimiento.
- **Anticipos**: se registran cuando se pagan y se descuentan solos en la próxima liquidación.

## Ausencias y vacaciones (fase 98)

**Sueldos → Ausencias y vacaciones**: cargás vacaciones, enfermedad, accidente de trabajo, examen, matrimonio, nacimiento, fallecimiento, mudanza, licencia sin goce, falta injustificada, suspensión y otras. Elegís empleado, tipo, desde y hasta: los días se cuentan solos (corridos para vacaciones y licencias; sin domingos ni feriados para faltas, sin goce y suspensiones) y no deja que una se pise con otra. Cada una puede quedar **pendiente** (la carga un encargado) y el dueño la **aprueba o rechaza** desde el recuadro de arriba. Se marca si trajo certificado.

**Vacaciones por antigüedad** como dice la LCT (art. 150): hasta 5 años de antigüedad 14 días, hasta 10 años 21, hasta 20 años 28 y después 35; con menos de 6 meses, 1 día cada 20 trabajados. El tablero muestra por empleado cuántos le corresponden, el ajuste, los tomados y el **saldo**. Las vacaciones que se toman de enero a abril se imputan al año anterior (se gozan hasta el 30/4); se puede elegir otro período a mano.

**Al liquidar**, la tabla de novedades del mes ya viene con los **feriados del mes**, las **vacaciones** y las **faltas** de cada empleado, y los días normales ajustados (30 − feriados − vacaciones − faltas). Las licencias pagas (enfermedad, examen, matrimonio…) no tocan el recibo. Todo se puede corregir antes de calcular.

A Bigcito: "cargale vacaciones a Juan del 5 al 19 de enero", "¿cuántos días de vacaciones le quedan a María?", "¿quién falta hoy?", "¿qué licencias hubo en marzo?".

## Feriados (fase 98)

En la misma pantalla, a la derecha, el **calendario de feriados** del año: los nacionales se calculan solos (ley 27.399: los trasladables que caen martes o miércoles pasan al lunes anterior; jueves o viernes, al lunes siguiente), más los **propios** de la empresa (día del gremio, aniversario) y los **puentes turísticos** del año, que se cargan con **Feriado propio**. Si en tu rubro un feriado nacional se trabaja, **quitar** lo saca del calendario de esta empresa (y **volver** lo restituye). La cantidad de feriados del mes sale de acá a la liquidación.

A Bigcito: "¿cuándo es el próximo feriado?", "¿cuántos feriados tiene mayo?".

## Para el contador

**Libro CSV** (libro de sueldos) y **F.931**: resumen por empleado con remuneración, no remunerativo, aportes, contribuciones y neto, listo para cargar en SICOSS.

### Libro de Sueldos Digital (fase 98)

Con la liquidación confirmada, **Libro de Sueldos Digital** baja el archivo de importación de ARCA (registros 01 datos de la liquidación, 02 empleado, 03 conceptos liquidados y 04 datos del F.931, ancho fijo, dos decimales implícitos). Los códigos de concepto son los de acá (BAS, ANT, JUB, 1200…): se declaran **una sola vez** en la solapa Conceptos del LSD y se mapean a los de ARCA. Las contribuciones patronales no van al libro. La situación de revista, condición, modalidad de contratación, actividad y zona salen de **Preferencias de sueldos → Libro de Sueldos Digital** (lo general) y de la ficha de cada empleado (si difiere). El archivo se llama `LSD_CUIT_AAAAMM_TIPO.txt`. En la primera presentación, revisá el archivo con el contador: el LSD valida CUIL, conceptos mapeados y topes.

A Bigcito: "¿cómo viene el libro de sueldos de octubre?" te dice empleados, conceptos, remuneración, a quién le falta el CUIL y el link de descarga.

### Ganancias 4ta categoría (fase 98)

En **Preferencias de sueldos → Ganancias 4ta categoría** el contador carga la tabla vigente: mínimo no imponible, deducción especial, cónyuge, por hijo, topes de alquiler y servicio doméstico y la **escala del art. 94** (tramos anuales: más de, hasta, fijo y %). ARCA la actualiza por semestre. Con la tabla activa, cada recibo lleva el concepto **GAN – Impuesto a las ganancias**: el sistema acumula la remuneración del año, resta aportes y deducciones proporcionales al mes, aplica la escala proporcional y retiene la diferencia con lo ya retenido; si da negativo, **devuelve**. Las deducciones de cada empleado (cónyuge, hijos, alquiler, servicio doméstico, otras del F.572) se cargan en su ficha. Sin tabla cargada, no aparece el concepto.

## Enviar los recibos para firmar

En **Sueldos**, con la liquidación confirmada, **Enviar para firmar** le manda a cada empleado su link por mail (si tiene) y por WhatsApp. El empleado abre el link desde el celular, sin usuario, mira su recibo y **firma con el dedo**. Puede firmar conforme o **en disconformidad**, diciendo en qué no está de acuerdo. Queda guardada la fecha, la hora, la IP y el navegador, y el recibo muestra "Firmado digitalmente".

Al lado de cada recibo se ve el estado: enviado, lo vio, firmado o firmó disconforme. **Reenviar a los que faltan** vuelve a mandar sólo a los que no firmaron.

**La firma del empleador** se carga una sola vez (Sueldos → Firmas y recibos → Firma del empleador) y sale en todos los recibos.

**Historial**: en Firmas y recibos, quién lo recibió, cuándo lo vio y cuándo lo firmó, con la IP. Se baja a Excel.

### El recibo explicado (Fase 65)

En el link que recibe el empleado, debajo del recibo, hay un botón **"¿Qué significa cada renglón? Te lo explico"**: qué ganó, qué le descontaron y para qué sirve cada descuento (jubilación, obra social, PAMI, sindicato), cuánto cobra en mano y cuánto paga la empresa además. Con clave de IA es un texto a su medida; sin clave, con reglas. Vos también lo podés pedir: "Bigcito, explicame el recibo de Juan" y se lo mandás.

## Recibos que vienen de afuera

Si liquidás con otro programa o lo hace el contador, subí el **PDF de la liquidación entera** en Firmas y recibos → Recibos externos. El sistema lo **separa por hoja**, lee cada una y la asigna a su empleado por **CUIL, DNI o legajo** (y por último por el nombre). Lo que no pudo asignar te lo pregunta. Si subís el mismo PDF otra vez, aunque tenga otro nombre, no se duplica. Después se mandan y se firman igual que los de acá.

**Recibos escaneados:** cuando la hoja es una foto y no tiene texto adentro, la IA de la empresa la lee y saca el nombre, el CUIL, el DNI, el legajo y el período. Con eso la asigna, pero **solo si el número coincide exacto**; nunca la asigna por parecido. En la lista figura «leído con IA». Necesita la clave de IA cargada en Configuración → IA. Si no está, la hoja queda para que la asignes a mano.

## Datos del período y costo laboral

En **Datos del período** cargás una vez por mes la **fecha de pago** y el **último depósito jubilatorio** (fecha, banco y mes), y salen en todos los recibos del período. Al lado, el **costo laboral de los últimos 12 meses**: bruto, no remunerativo y contribuciones del empleador, mes por mes.
