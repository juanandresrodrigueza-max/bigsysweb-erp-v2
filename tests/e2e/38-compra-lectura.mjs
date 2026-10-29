// Fase 31.1–31.4: la carga de factura con el modo remito, la factura de un remito, comparar con la orden y "dejarla para después".
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'compra-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/proveedores/compras/nueva`); await idle()
console.log('  modo de carga:', (await page.innerText('[data-e2e="modo-carga"]')).replace(/\s+/g, ' ').trim().slice(0, 160))
await shot('01-form')
await page.goto(`${BASE}/proveedores/compras`); await idle()
console.log('  a medio cargar visible:', await page.locator('[data-e2e="a-medio-cargar"]').isVisible().catch(() => false))
await fin(browser)
