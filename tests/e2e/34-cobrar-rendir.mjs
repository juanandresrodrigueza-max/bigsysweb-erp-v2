// Fase 29.5: el vendedor cobra en la calle con firma, confirma una entrega, cierra el turno y rinde; el encargado recibe la plata.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page: admin } = await abrir()
const ctx = await browser.newContext({ viewport: { width: 430, height: 900 } }); const page = await ctx.newPage()
const idle = () => _idle(page); const shot = n => _shot(page, 'cobrar-' + n)
await _login(page, 'vendedor@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/vender?modo=calle`); await idle()
await page.waitForSelector('[data-e2e^="grupo-"]', { timeout: 20000 })
if (!(await page.locator('[data-e2e^="venderle-"]').first().isVisible())) await page.locator('[data-e2e^="grupo-"]').first().click()
await page.locator('[data-e2e^="venderle-"]').first().click(); await page.waitForTimeout(800)
// Cobrar 1.000 en efectivo con firma.
await page.click('[data-e2e="cobrar"]'); await page.fill('[data-e2e="cobro-monto-0"]', '1000')
const cv = page.locator('canvas').first(); const b = await cv.boundingBox(); await page.mouse.move(b.x + 20, b.y + 40); await page.mouse.down(); await page.mouse.move(b.x + 120, b.y + 80); await page.mouse.move(b.x + 200, b.y + 30); await page.mouse.up()
await shot('01-cobro')
await page.click('[data-e2e="cobro-confirmar"]'); await page.waitForTimeout(1500)
console.log('  cobro:', (await page.innerText('[data-e2e="resultado"]')).replace(/\s+/g, ' ').trim())
// Una factura desde la calle y su confirmación de entrega.
if (await page.locator('[data-e2e="atajo-todo"]').isVisible().catch(() => false)) await page.click('[data-e2e="atajo-todo"]')
await page.locator('[data-e2e^="prod-"] input[type=checkbox]').first().check(); await page.click('[data-e2e="agregar"]'); await page.click('[data-e2e="tipo-FX"]'); await page.click('[data-e2e="confirmar"]'); await page.waitForTimeout(1500)
if (await page.locator('[data-e2e="vender-igual"]').isVisible().catch(() => false)) { await page.click('[data-e2e="vender-igual"]'); await page.waitForTimeout(1500) }
console.log('  venta:', (await page.innerText('[data-e2e="resultado"]')).replace(/\s+/g, ' ').trim())
await page.click('[data-e2e="entregar-ahora"]'); await page.fill('[data-e2e="entrega-nombre"]', 'María Pérez'); await page.click('[data-e2e="entrega-confirmar"]'); await page.waitForTimeout(1200)
// Hoy: en mano y rendir.
await page.click('[data-e2e="ir-hoy"]'); await page.waitForTimeout(1200)
console.log('  hoy:', (await page.innerText('[data-e2e="pantalla-hoy"]')).replace(/\s+/g, ' ').trim().slice(0, 260))
await shot('02-hoy')
page.on('dialog', d => d.accept())
await page.click('[data-e2e="rendir"]'); await page.waitForTimeout(1500)
console.log('  rendido:', (await page.innerText('[data-e2e="resultado"]')).replace(/\s+/g, ' ').trim())
// El encargado la recibe.
await _login(admin, 'demo@bigsys.com.ar'); await admin.click('button:has-text("Saltar")').catch(() => {})
await admin.goto(`${BASE}/clientes/vendedores`); await _idle(admin)
const r = admin.locator('[data-e2e^="rendicion-"]').first(); console.log('  pendiente:', (await r.innerText()).replace(/\s+/g, ' ').trim().slice(0, 160))
await r.locator('[data-e2e^="contado-"]').fill('990'); await r.locator('[data-e2e^="recibir-"]').click(); await _idle(admin); await admin.waitForTimeout(800)
await _shot(admin, 'cobrar-03-recibida')
console.log('  recibida:', (await admin.innerText('[data-e2e="rendiciones"]')).replace(/\s+/g, ' ').trim().slice(0, 300))
await fin(browser)
