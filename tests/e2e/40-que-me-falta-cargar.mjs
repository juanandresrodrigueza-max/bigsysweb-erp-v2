// Fase 31.6: Qué me falta cargar: subir el CSV de Mis Comprobantes recibidos, ver las solapas y crear las que faltan desde ahí.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
import { writeFileSync } from 'node:fs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'afip-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/proveedores/compras`); await idle()
// un CSV como el que baja ARCA: una que ya está (Loma Negra 0012-00046120) y dos que faltan
const n = Date.now() % 1000000
const csv = ['Fecha de Emisión;Tipo de Comprobante;Punto de Venta;Número Desde;Número Hasta;Cód. Autorización;Tipo Doc. Emisor;Nro. Doc. Emisor;Denominación Emisor;Tipo Cambio;Moneda;Imp. Neto Gravado;Imp. Neto No Gravado;Imp. Op. Exentas;Otros Tributos;IVA;Imp. Total',
  '17/10/2026;1;12;46120;46120;71234;80;30500000001;LOMA NEGRA SA;1,000000;PES;2.900.000,00;0,00;0,00;0,00;609.000,00;3.509.000,00',
  `20/10/2026;1;12;${n};${n};71235;80;30500000001;LOMA NEGRA SA;1,000000;PES;100.000,00;0,00;0,00;0,00;21.000,00;121.000,00`,
  `21/10/2026;6;3;${n};${n};71236;80;20300000004;FERRETERIA NUEVA;1,000000;PES;0,00;0,00;0,00;0,00;0,00;8.000,00`].join('\n')
const ruta = `/tmp/claude-0/recibidos-${n}.csv`; writeFileSync(ruta, csv)
await page.click('[data-e2e="importar-afip"]'); await page.waitForTimeout(400)
await page.setInputFiles('[data-e2e="afip-archivo"]', ruta)
await page.click('[data-e2e="cruzar"]'); await page.waitForTimeout(1500)
await shot('01-cruce')
console.log('  solapas:', (await page.innerText('[data-e2e="cruce-solapas"]')).replace(/\s+/g, ' '))
const faltan = await page.$$eval('.fixed.inset-0 tbody tr', tr => tr.map(t => t.innerText.replace(/\s+/g, ' ').slice(0, 90)))
console.log('  faltan:', faltan)
await page.click('[data-e2e="cruce-solapas"] button:has-text("No cierran")'); await page.waitForTimeout(200); await shot('02-no-cierran')
await page.click('[data-e2e="cruce-solapas"] button:has-text("Faltan")'); await page.waitForTimeout(200)
console.log('  botón crear:', await page.innerText('[data-e2e="crear-faltantes"]'))
await page.click('[data-e2e="crear-faltantes"]'); await idle(); await page.waitForTimeout(800)
const flash = (await page.innerText('body')).match(/Importación AFIP[^\n]*/)?.[0]
console.log('  flash:', flash)
await shot('03-creadas')
await fin(browser)
