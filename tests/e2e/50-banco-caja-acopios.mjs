// Fase 35.3/35.5/35.6: los tres cajones del banco, la planilla de caja y los acopios.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
import { writeFileSync } from 'node:fs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'banco-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
// Banco: importar un extracto chico con impuestos y una acreditación de tarjeta
const t = Date.now() % 100000; const h = new Date(); const f = `${String(h.getDate()).padStart(2, '0')}/${String(h.getMonth() + 1).padStart(2, '0')}/${h.getFullYear()}`
const ext = `/tmp/ext-${t}.csv`; writeFileSync(ext, `Fecha;Concepto;Importe\n${f};IMP LEY 25413 DEB ${t};-1${t % 100},00\n${f};COMISION MANT CTA ${t};-25${t % 10},00\n${f};FISERV LIQ ${t};7${t},00\n`)
await page.goto(`${BASE}/contable/conciliacion`); await idle()
await page.click('button:has-text("Importar extracto")'); await page.setInputFiles('input[type=file]', ext); await page.locator('.fixed.inset-0 .btn-primary').last().click(); await idle(); await page.waitForTimeout(800)
console.log('  cajones:', (await page.innerText('[data-e2e="cajones"]').catch(() => '(no hay)')).replace(/\s+/g, ' ').slice(0, 220))
console.log('  puente:', (await page.innerText('[data-e2e="puente"]').catch(() => '')).replace(/\s+/g, ' ').slice(0, 200))
await shot('01-cajones')
// Caja: planilla
await page.goto(`${BASE}/fondos/cierres`); await idle()
await page.click('[data-e2e="abrir-planilla"]')
const pl = `/tmp/planilla-${t}.csv`; writeFileSync(pl, `Fecha;Concepto;Recibo;Ingreso;Egreso\n${f};Cobro;88${t};1000;\n${f};Gasto;;;200\n`)
await page.setInputFiles('[data-e2e="archivo-planilla"]', pl); await page.waitForSelector('[data-e2e="planilla-resumen"]', { timeout: 15000 })
console.log('  planilla:', await page.innerText('[data-e2e="planilla-resumen"]'))
await shot('02-planilla')
// Acopios
await page.goto(`${BASE}/clientes/cobranzas`); await idle(); await page.click('[data-ir-acopios]'); await idle()
console.log('  acopios:', (await page.innerText('[data-e2e="totales"]')).replace(/\s+/g, ' '))
await shot('03-acopios')
await fin(browser)
