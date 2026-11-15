# Guiones de los videos cortos (fase 100)

Cinco videos de 60 a 90 segundos, grabados con la pantalla del ERP (demo `demo@bigsys.com.ar`) y el celular. Voz en off en rioplatense, sin música fuerte, subtítulos siempre (se miran sin audio). Cada uno termina con la misma placa: **«Probalo gratis 15 días en erp.bigsysweb.com»**.

Formato: vertical 9:16 para redes y WhatsApp; el mismo corte en 16:9 para la web. Capturas de la app a 390 × 844 en modo claro (como pide `docs/MOVIL.md`).

---

## 1 · Vender en el mostrador (60 s)

| Seg. | Pantalla | Voz |
|---|---|---|
| 0–5 | Logo, mostrador abierto | «Esto es BigSys Web. Vendé así.» |
| 5–20 | Escanear o escribir dos artículos, elegir cliente «Consumidor final», cobrar en efectivo | «Cargás, cobrás, listo. El ticket sale solo y el stock baja solo.» |
| 20–35 | Cambiar el cliente a uno responsable inscripto: la letra pasa a A sola | «Si el cliente es responsable inscripto, la factura A sale sola, con CAE de ARCA. No tenés que pensar la letra.» |
| 35–50 | Tilde «comprobante interno» en una venta que no va a ARCA; marca de agua «No válido como factura» | «Lo que no es una venta, por ejemplo una entrega de mercadería a consignación, lo registrás como comprobante interno: mueve stock y caja, no va a ARCA. Y si después hay que facturarlo, un botón lo pasa a fiscal.» |
| 50–60 | Placa final | «Probalo gratis 15 días.» |

## 2 · Bigcito por voz (75 s)

| Seg. | Pantalla | Voz |
|---|---|---|
| 0–5 | Celular, app abierta, cara de Bigcito | «Este es Bigcito. Hablale.» |
| 5–20 | Tocar el micrófono: «¿Cuánto vendí hoy?». Responde en voz alta con el número | (se escucha la respuesta de Bigcito) |
| 20–40 | «Cobrale 10.000 a Pérez en efectivo». Bigcito propone, pide confirmar, el usuario dice «dale» | «Las acciones siempre se confirman. Y queda registrado quién hizo qué.» |
| 40–60 | Sacarle foto a una factura de compra: Bigcito la carga como compra | «Sacale una foto a la factura del proveedor y la carga él.» |
| 60–75 | Placa final | «Bigcito entiende voz, fotos y audios. Probalo gratis.» |

## 3 · Bigcito trabaja de noche (90 s)

| Seg. | Pantalla | Voz |
|---|---|---|
| 0–8 | Celular a la mañana, notificación «Bigcito te dejó 4 propuestas» | «Mientras dormís, Bigcito revisa tu negocio.» |
| 8–25 | Abrir Resumen hablado, tocar Escuchar: se oye el parte | «Te lee cómo cerró ayer y qué vence hoy.» |
| 25–45 | Pantalla Propuestas: el cobrador con el WhatsApp ya redactado para un cliente. Tocar Aprobar | «Decide a quién cobrarle y te escribe el mensaje según cómo paga ese cliente. Vos aprobás, y sale.» |
| 45–60 | Propuesta de reposición: orden por proveedor con el total. Aprobar | «Arma la orden de compra con lo que falta y cuánta plata hace falta.» |
| 60–75 | Propuesta de precios: subió el costo, precio nuevo con el margen. Aprobar | «Si sube el costo, te propone el precio nuevo respetando tu margen.» |
| 75–90 | Placa final | «Nada se hace solo: vos aprobás de a un toque. Probalo gratis.» |

## 4 · Cerrar la caja y la plata (60 s)

| Seg. | Pantalla | Voz |
|---|---|---|
| 0–5 | Plata → Cerrar caja | «Fin del día.» |
| 5–25 | Arqueo: contar efectivo, el sistema muestra esperado y diferencia | «Contás la plata, el sistema te dice cuánto tendría que haber y cuánto hay de diferencia. Por medio de pago.» |
| 25–45 | Contable → Conciliación: importar extracto, las reglas aplican solas; «Traer de Mercado Pago» | «El extracto del banco y Mercado Pago se concilian solos con las reglas que aprende de lo que hacés.» |
| 45–60 | Placa final | «Sabé dónde está la plata todos los días. Probalo gratis.» |

## 5 · El contador entra solo (60 s)

| Seg. | Pantalla | Voz |
|---|---|---|
| 0–5 | Contable → Contador | «Tu contador ya no te pide nada.» |
| 5–25 | Checklist del mes, libros de IVA, posición IVA con «Copiar para el contador» | «Entra con su usuario, ve los libros, la posición de IVA y el checklist del mes.» |
| 25–45 | Exportar asientos a Tango / Holistor / Bejerman; propuesta de cierre del mes de Bigcito | «Exporta los asientos a su sistema. Y Bigcito le deja los ajustes del cierre propuestos: él sólo acepta.» |
| 45–60 | Placa final | «Probalo gratis 15 días. Tu contador te lo va a agradecer.» |

---

## Para grabar

- Demo con datos: correr `php artisan agentes:correr --empresa=<id>` antes del video 3 para tener propuestas frescas.
- Ocultar el banner de «Primera semana» y los tips (botón Entendido) antes de grabar.
- Voz: la del celular para el video 3 (es la real); voz en off humana para el resto.
- Textos de las tiendas y capturas: `docs/MOVIL.md` → «Ficha para las tiendas».
