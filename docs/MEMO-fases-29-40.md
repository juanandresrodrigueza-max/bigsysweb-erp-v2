# Memo: fases 29 a 40 (equivalencias con BIG Connect)

Estado: **plan completo**. Fases 29 a 40 cerradas y mergeadas en `main` (PRs #22 a #52). Las fases 41 y 42 quedaron afuera a pedido.
Pruebas: 300+ tests en verde en SQLite y PostgreSQL, y una prueba de navegador por fase (`tests/e2e/`).

## Qué se hizo, por fase

| Fase | Tema | Lo principal |
|---|---|---|
| 29 | Vendedores en la calle | Ruta, visitas, objetivos, "Mi reporte", equipo en Estadísticas → Vendedores |
| 30 | Tiendas online | MercadoLibre, Tienda Nube, WooCommerce y Shopify conectados; publicar desde el catálogo; ventas listas para facturar |
| 31–33 | Comercial y compras | (ver bitácora) |
| 34 | Precios y datos en masa | Previsualizar, aplicar y deshacer; importar la lista del proveedor con mapeo recordado |
| 35 | Conciliaciones | Tarjetas y billeteras, los tres cajones del extracto, planilla de caja, acopios |
| 36 | Servicio técnico | Rubros, aprobación por ítem, técnicos y su pago, acta de recepción con fotos, "que vuelvan", vida del equipo |
| 37 | Reparto y pases | Armar el viaje, vista del chofer y seguimiento públicos, pases entre sucursales |
| 38 | Recibos de sueldo firmados | Firma desde el celular con enlace, PDF dividido por empleado |
| 39 | Multiempresa y estudio | Perfiles listos, acceso por empresa validado, conjuntos que suman sin ventas internas, semáforo y vencimientos |
| 40 | IA de gestión, ayuda y avisos | Bigcito aconseja (proyección, deudas, a quién cobrar), fotos y audios, comprobantes sin cliente, ayuda que aprende, avisos al celular, novedades, parte de la mañana, mejoras |

## Bigcito y MCP

Todo lo de las fases 34 a 40 se puede pedir por voz a Bigcito o desde Claude y ChatGPT por MCP (`app/Mcp/Modulos/*.php`).
Las acciones siempre piden confirmación, que se puede dar diciendo "sí" o "dale", y quedan auditadas.
Para preguntas de "¿cómo hago…?", Bigcito lee la sección entera del manual (`ayuda_sistema`) y no inventa pasos.

## Manuales

- Guías de usuario: `docs/ayuda/*.md`, que se ven en **Ayuda**, en el botón **?** de cada pantalla y en el manual completo descargable.
- Todas las pantallas con menú tienen guía. Para comprobarlo, se recorren las rutas y se busca `AyudaService::paraRuta()` de cada una.
- Guías técnicas: `docs/arca.md`, `asistente.md`, `atajos.md`, `mercado-argentino.md`, `seguridad.md` y `api.md`.

## Para publicar

Ver [`docs/DEPLOY-hostinger.md`](DEPLOY-hostinger.md): VPS de Hostinger con Docker y deploy automático en cada merge a `main`.

## Para probar a mano una vez publicado

- **Avisos al celular:** activarlos en un celular real y mandar uno de prueba. En el navegador headless no hay servicio de push.
- **Audio en Bigcito:** cargar una clave de OpenAI o Gemini en Conexiones IA y mandar un audio.
- **Foto en Bigcito:** probar con la clave de Claude de la empresa.

## Mantenimiento

- Una vez por año, actualizar la tabla de vencimientos de IVA por terminación de CUIT (estudio contable) cuando ARCA publique el calendario.
