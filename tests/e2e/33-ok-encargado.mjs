// Fase 29.4: descuento por encima del tope. El vendedor pone 30 %, la app pide el OK del encargado en el mismo teléfono y la venta entra.
import { abrir, idle as _idle, shot as _shot, login as _login, salir, fin, BASE } from './lib.mjs'
const { browser, page: admin } = await abrir()
let page = admin
const idle = () => _idle(page); const shot = n => _shot(page, 'ok-' + n)
// El dueño pone el tope en 10 %.
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/clientes/vendedores`); await idle()
await page.locator('[data-e2e="config-calle"] input[type=number]').first().fill('10'); await page.click('[data-e2e="guardar-calle"]'); await idle(); await page.waitForTimeout(500)
// El vendedor, en otro navegador (su teléfono).
const ctx = await browser.newContext({ viewport: { width: 430, height: 900 } }); page = await ctx.newPage()
// El vendedor arma el pedido con 30 % en un renglón.
await _login(page, 'vendedor@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/vender?modo=calle`); await idle()
await page.waitForSelector('[data-e2e^="grupo-"]', { timeout: 20000 })
if (!(await page.locator('[data-e2e^="venderle-"]').first().isVisible())) await page.locator('[data-e2e^="grupo-"]').first().click()
await page.locator('[data-e2e^="venderle-"]').first().click(); await page.waitForTimeout(800)
if (await page.locator('[data-e2e="atajo-todo"]').isVisible().catch(() => false)) await page.click('[data-e2e="atajo-todo"]')
await page.locator('[data-e2e^="prod-"] input[type=checkbox]').first().check(); await page.click('[data-e2e="agregar"]'); await page.waitForTimeout(300)
const dto = page.locator('[data-e2e^="dto-"]').first(); await dto.fill('30'); await dto.dispatchEvent('change'); await page.waitForTimeout(200)
console.log('  aviso:', (await page.innerText('[data-e2e="aviso-tope"]')).trim())
await shot('01-aviso-tope')
await page.click('[data-e2e="confirmar"]'); await page.waitForTimeout(500)
// Autorizarse a sí mismo no vale.
await page.fill('[data-e2e="ok-email"]', 'vendedor@bigsys.com.ar'); await page.fill('[data-e2e="ok-clave"]', 'password'); await page.click('[data-e2e="ok-autorizar"]'); await page.waitForTimeout(1200)
console.log('  a sí mismo:', (await page.locator('.text-carmin').last().innerText()).trim())
await shot('02-modal-ok')
// El dueño pone su clave.
await page.fill('[data-e2e="ok-email"]', 'demo@bigsys.com.ar'); await page.fill('[data-e2e="ok-clave"]', 'password'); await page.click('[data-e2e="ok-autorizar"]'); await page.waitForTimeout(2500)
console.log('  resultado:', (await page.innerText('[data-e2e="resultado"]')).replace(/\s+/g, ' ').trim())
await shot('03-vendido')
await fin(browser)
