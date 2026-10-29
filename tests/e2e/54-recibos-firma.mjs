// Fase 38: firma del empleador, PDF externo separado y repartido por CUIL, enviar y que el empleado firme desde el link.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, ctx, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'recibos-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/sueldos/recibos`); await idle()
await page.click('[data-tab="empleador"]'); const cv = page.locator('[data-e2e="empleador"] canvas'); const b = await cv.boundingBox()
await page.mouse.move(b.x + 40, b.y + 90); await page.mouse.down(); await page.mouse.move(b.x + 200, b.y + 60); await page.mouse.move(b.x + 320, b.y + 120); await page.mouse.up()
await page.locator('[data-e2e="empleador"] input').fill('Dueño E2E'); await page.click('[data-e2e="empleador"] button.btn-primary'); await idle(); await page.waitForTimeout(400)
console.log('  firma empleador:', (await page.innerText('[data-e2e="empleador"]')).replace(/\s+/g, ' ').slice(0, 90))
await page.click('[data-tab="externos"]'); await page.setInputFiles('[data-e2e="pdf"]', '/tmp/rec-1793279828.pdf'); await page.click('[data-e2e="subir-pdf"]'); await idle(); await page.waitForTimeout(600)
console.log('  externos:', (await page.innerText('[data-e2e="externos"] table')).replace(/\s+/g, ' ').slice(0, 220))
await shot('01-externos')
const ver = page.locator('[data-e2e="externos"] button:has-text("enviar")').first()
if (await ver.count()) { await ver.click(); await idle() }
await page.click('[data-tab="firmas"]'); console.log('  firmas:', (await page.innerText('[data-e2e="firmas"]')).replace(/\s+/g, ' ').slice(0, 200))
await shot('02-firmas')
// El empleado firma desde su link (el token lo pasa el script que lo llama)
if (process.env.TOKEN) { const emp = await ctx.newPage(); await emp.goto(`${BASE}/recibo/${process.env.TOKEN}`); const c = emp.locator('#c'); const bb = await c.boundingBox()
  await emp.mouse.move(bb.x + 30, bb.y + 80); await emp.mouse.down(); await emp.mouse.move(bb.x + 220, bb.y + 40); await emp.mouse.move(bb.x + 330, bb.y + 110); await emp.mouse.up()
  await emp.click('button:has-text("Firmar recibo")'); await emp.waitForLoadState('networkidle'); console.log('  empleado:', (await emp.innerText('.card.ok')).replace(/\s+/g, ' ')); await _shot(emp, 'recibos-03-empleado') }
await fin(browser)
