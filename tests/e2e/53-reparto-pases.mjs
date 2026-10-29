// Fase 37: armar el viaje, el chofer (salí, entregué, no se pudo), el cliente sigue el pedido; pase entre sucursales enviado y recibido contando.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, ctx, page } = await abrir(undefined, { geolocation: { latitude: -31.42, longitude: -64.19 }, permissions: ['geolocation'] })
const idle = () => _idle(page); const shot = n => _shot(page, 'reparto-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/comprobantes/entregas`); await idle(); await page.click('[data-e2e="armar"]'); await idle()
const docs = page.locator('[data-doc]'); console.log('  pendientes:', await docs.count())
await page.click('button:has-text("+ parada")'); await page.locator('input[placeholder^="Qué"]').fill('Retirar cheques e2e'); await page.locator('input[placeholder="Dónde"]').fill('San Martín 100')
if (await docs.count()) await docs.first().check()
await page.fill('[data-e2e="chofer"]', 'Carlos E2E')
console.log('  carga:', (await page.innerText('[data-e2e="carga"]')).replace(/\s+/g, ' ').slice(0, 120))
await shot('01-armar')
await page.click('[data-e2e="armar-viaje"]'); await idle(); await page.waitForURL(/entregas\/\d+/)
console.log('  viaje:', (await page.innerText('[data-e2e="viaje"]')).replace(/\s+/g, ' ').slice(0, 160))
const chofer = await page.getAttribute('[data-e2e="link-chofer"]', 'href')
const ch = await ctx.newPage(); await ch.goto(chofer); await ch.click('button:has-text("Salí")'); await ch.waitForLoadState('networkidle')
await ch.locator('details summary:has-text("Entregué")').first().click(); await ch.locator('input[name="recibio"]').first().fill('Juan'); await ch.waitForTimeout(1200)
await ch.locator('button:has-text("Confirmar entrega")').first().click(); await ch.waitForLoadState('networkidle')
console.log('  chofer:', (await ch.innerText('.wrap')).replace(/\s+/g, ' ').slice(0, 160)); await _shot(ch, 'reparto-02-chofer'); await ch.close()
await page.reload(); await idle()
const seg = await page.locator('button:has-text("copiar link del cliente")').count(); console.log('  links de cliente:', seg)
// Pase
await page.goto(`${BASE}/stock/pases`); await idle()
const nd = await page.locator('[data-e2e="destino"] option').count(); console.log('  depósitos destino:', nd)
if (nd) {
  const sku = await page.evaluate(async () => { const r = await window.axios.get('/stock/precios/buscar', { params: { q: 'a' } }); return r.data[0]?.sku })
  await page.fill('[data-e2e="lector"]', `2*${sku}`); await page.press('[data-e2e="lector"]', 'Enter'); await page.waitForTimeout(600)
  await page.click('[data-e2e="enviar"]'); await idle(); await page.waitForURL(/pases\/\d+/).catch(() => {})
  const c = page.locator('[data-contar]'); if (await c.count()) { await c.first().fill('1'); await page.click('[data-e2e="recibir"]'); await idle(); await page.waitForTimeout(400) }
  console.log('  pase:', (await page.innerText('.page-subtitle')).slice(0, 100))
  await shot('03-pase')
}
await fin(browser)
