# BigSys Web ERP — lo que hay que saber antes de tocar nada

ERP en Laravel 11 + Inertia/Vue 3 (PostgreSQL en producción, SQLite en las pruebas). Producción: `erp.bigsysweb.com`. El CRM hermano (`crm.bigsysweb.com`) es otro repositorio.

## Con Juan (el dueño)

- **Siempre en español rioplatense.** Nunca en inglés, tampoco en los mensajes de avance.
- La empresa se escribe **BigSys** (B y S mayúsculas). Si un dictado dice «Vixis» o algo parecido, es BigSys. La razón social es **BIGSYS S.R.L., CUIT 30-71438573-5**. BIGSYS WEB S.A.S. es otra empresa: no se usa para facturar.
- **Cuando pregunte «¿qué me falta?»** (o «qué falta para estar operativo»), responder con lo que está sin tildar en `docs/PENDIENTES-OPERATIVOS.md`, en ese orden y en pocas líneas. Cuando algo quede hecho, tildarlo ahí.
- Le gusta el paso a paso con capturas: un paso por mensaje, diciendo exactamente qué botón tocar.

## Reglas que no se rompen

- **Ninguna clave, token, certificado ni contraseña por el chat.** Se cargan en los formularios del ERP (Panel → Sistema, Configuración) o en el servidor. Si una aparece en una captura, avisar y recomendar cambiarla.
- **Producción se actualiza sólo desde la sesión «CRM Producción»**, que Juan maneja, con `curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/actualizar.sh | bash`. Le pasamos el mensaje para que lo copie. En el servidor, tinker se corre con `XDG_CONFIG_HOME=/tmp`.
- **No tocar el certificado de ARCA del CRM** ni copiarlo al ERP. El ERP tiene el suyo: alias `bigsyserp`, que vence el 19/11/2028 (avisa solo 30, 7 y 1 día antes).
- **La empresa BigSys factura en el ERP con el punto de venta 0008.** Los puntos 0001, 0005, 0006 y 0007 son del CRM, de Odoo y de otros sistemas.
- Un PR por fase: pruebas en SQLite y PostgreSQL, commit, PR, squash merge. Cada fase suma su entrada al memo (`docs/MEMO-fases-57-78.md`), su ayuda en `docs/ayuda` si cambia algo que ve el usuario (es lo que lee Bigcito para contestar) y una novedad si corresponde.

## Dónde está cada cosa

- `docs/PENDIENTES-OPERATIVOS.md`: el repaso de lo que falta para estar 100 % operativo.
- `docs/MEMO-fases-57-78.md`: la bitácora de cada fase (qué se hizo, por qué y cómo).
- `docs/MANUAL-DATOS-Y-RESTAURACION.md`: copias y qué hacer en cada tipo de problema.
- `docs/ayuda/*.md`: el centro de ayuda (27 artículos). Bigcito busca ahí; `administracion-bigsys.md` es el del panel de BigSys.
- `docs/LEGAL-facturacion-por-bigsys.md`: lo legal de facturar por BigSys, para el abogado y el contador.
- Pruebas que fallan sin afectar producción: `MonitoreoTest` falla siempre; algunas de PostgreSQL fallan de vez en cuando por orden y pasan solas.
