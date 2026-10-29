// Fase 30.4–30.6: la bandeja de Pedidos con los avisos de cada venta y la exportación por período.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'pedidos-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/comprobantes/pedidos`); await idle()
const primero = page.locator('.divide-y button').first(); if (await primero.isVisible().catch(() => false)) { await primero.click(); await page.waitForTimeout(400) }
await shot('01-pedido')
await page.click('[data-e2e="exportar"]'); await page.waitForTimeout(1200)
console.log('  exportar:', (await page.innerText('[data-e2e="exportar-cuenta"]')).replace(/\s+/g, ' ').trim())
await shot('02-exportar')
await fin(browser)
