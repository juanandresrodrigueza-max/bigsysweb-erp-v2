// Fase 26.7: el plan muestra las facturas del mes y los artículos contra su límite.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'lim-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/suscripcion`); await idle()
const card = page.locator('[data-e2e="uso-facturas"]')
console.log('  facturas del mes en Suscripción:', (await card.innerText()).replace(/\s+/g, ' ').trim())
console.log('  los planes informan el límite:', await page.locator('li', { hasText: 'facturas por mes' }).count() > 0 || await page.locator('li', { hasText: 'Facturas sin límite' }).count() > 0)
await shot('01-suscripcion')
await fin(browser)
