// Fase 35.1/35.2/35.4: qué es cada fondo, subir una liquidación de Fiserv, el resultado en cuatro grupos, resolver, probar, asentar y deshacer.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
import { writeFileSync } from 'node:fs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'conciliar-' + n)
page.on('dialog', d => d.accept())
const hoy = new Date(); const d = n => { const x = new Date(hoy); x.setDate(x.getDate() + n); return `${String(x.getDate()).padStart(2, '0')}/${String(x.getMonth() + 1).padStart(2, '0')}/${x.getFullYear()}` }
const imp = 1000 + (Date.now() % 9000)
const csv = `Fiserv\nFecha de venta;Nro Ticket;Importe;Fecha de pago;Arancel;IVA Arancel;Neto;Nro de Liquidacion\n${d(0)};1;${imp},00;${d(2)};15,00;3,15;${imp - 18.15};E2E-${imp}\n${d(-1)};;77777,77;${d(2)};0;0;77777,77;E2E-${imp}\n`
const f = `/tmp/fiserv-e2e-${imp}.csv`; writeFileSync(f, csv)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/fondos`); await idle(); await page.click('[data-ir-conciliar]'); await idle()
console.log('  fondos:', (await page.innerText('[data-e2e="fondos"]')).replace(/\s+/g, ' ').slice(0, 160))
await shot('01-index')
if (!(await page.locator('[data-e2e="fondo"] option').count())) { console.log('  (sin fondo de tarjeta en la demo)'); await fin(browser); process.exit(0) }
await page.setInputFiles('[data-e2e="archivo"]', f); await page.click('[data-e2e="cruzar"]'); await idle()
await page.waitForSelector('[data-e2e="numeros"]', { timeout: 20000 }).catch(async () => console.log('  error:', await page.innerText('[data-e2e="error"]').catch(() => '?')))
console.log('  números:', (await page.innerText('[data-e2e="numeros"]')).replace(/\s+/g, ' '))
console.log('  control:', await page.innerText('[data-e2e="control"]'))
await shot('02-resultado')
if (await page.locator('[data-e2e="descartar"]').count()) { await page.click('[data-tab="revisar"]'); await page.locator('[data-e2e="descartar"]').first().click(); await page.waitForFunction(() => document.querySelector('[data-e2e="revisar-n"]')?.innerText.trim() === '1', null, { timeout: 10000 }).catch(() => {}); console.log('  a revisar después:', await page.innerText('[data-e2e="revisar-n"]')) }
console.log('  asentar:', (await page.innerText('[data-e2e="asentar"]')).replace(/\s+/g, ' ').slice(0, 220))
await shot('03-previa')
await fin(browser)
