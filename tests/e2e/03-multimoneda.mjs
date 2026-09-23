// Fase 24.1: compra en dólares, factura en dólares cobrada con diferencia de cambio y recibo a cuenta aplicado después.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const PREFIX = 'multimoneda-'
const idle = () => _idle(page); const shot = n => _shot(page, PREFIX + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
// 1. Compra en USD
await page.goto(`${BASE}/proveedores/compras/nueva`); await idle()
const prov = page.locator('input[placeholder^="Buscar proveedor"]'); await prov.click(); await prov.fill('a'); await page.waitForTimeout(500); await page.keyboard.press('Enter'); await page.waitForTimeout(300)
await page.selectOption('label:has-text("Moneda") + div select', 'USD'); await page.waitForTimeout(150)
await page.fill('input[placeholder="$ por USD"]', '1000')
await page.fill('input[placeholder="0003-00012345"]', '0009-' + String(Date.now() % 100000000).padStart(8, '0'))
await page.click('button:has-text("Agregar")'); const art = page.locator('input[placeholder="Sin artículo (solo gasto)"]').first(); await art.click(); await art.fill('cem'); await page.waitForTimeout(500); await page.keyboard.press('Enter'); await page.waitForTimeout(300)
await page.locator('tbody input[type=number]').nth(0).fill('10'); await page.locator('tbody input[type=number]').nth(1).fill('5')
console.log('  encabezado USD:', await page.locator('th:has-text("(USD)")').count(), '· total:', (await page.locator('.sticky .text-lg span.tabular-nums').textContent()).trim(), '· en pesos:', (await page.locator('text=En pesos a').count()))
await shot('01-compra-usd')
await page.click('button:has-text("Registrar compra")'); await idle(); await page.waitForTimeout(700)
console.log('  compra:', page.url().replace(BASE, ''), '· línea USD:', (await page.locator('text=En USD a').count()))
await shot('02-compra-ver')
// 2. Proveedor: pendientes muestran saldo USD y cotización del pago
await page.locator('a.btn-primary:has-text("Registrar pago")').click(); await idle(); await page.waitForTimeout(600)
console.log('  badge USD en pendientes:', await page.locator('.fixed .badge:has-text("USD")').count(), '· cotización del pago:', await page.locator('text=Cotización del pago').count(), '· aviso a cuenta:', await page.locator('text=queda a cuenta y se imputa').count())
await shot('03-pago-usd')
await page.keyboard.press('Escape').catch(() => {})
// 3. Cliente: recibo a cuenta y botón "Aplicar a facturas"
await page.goto(`${BASE}/clientes`); await idle(); await page.locator('tbody tr').first().click(); await idle()
await page.click('button:has-text("Registrar cobro")'); await page.waitForTimeout(400)
await page.locator('.fixed input[type=number]').first().fill('1000')
console.log('  a cuenta visible:', await page.locator('.fixed :text("A cuenta")').count())
await page.locator('.fixed button:has-text("Registrar")').last().click(); await idle(); await page.waitForTimeout(600)
await page.click('button:has-text("Cobros")'); await page.waitForTimeout(300)
const aplicar = page.locator('button:has-text("Aplicar a facturas")')
console.log('  botón aplicar:', await aplicar.count())
if (await aplicar.count()) { await aplicar.first().click(); await page.waitForTimeout(300); console.log('  modal aplicar:', await page.locator('.fixed :text("Repartilo entre las facturas")').count()); await shot('04-aplicar-a-cuenta'); await page.locator('.fixed button:has-text("Aplicar")').last().click(); await idle(); await page.waitForTimeout(500); console.log('  aplicado:', (await page.locator('.bg-emerald-50').first().textContent().catch(() => '')).trim().slice(0, 60)) }
await shot('05-cobros')
await fin(browser)
