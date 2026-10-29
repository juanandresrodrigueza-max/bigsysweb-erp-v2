// Fase 30.1/30.2: las tarjetas de tiendas en Configuración, pegar claves de Shopify (falla con claridad sin tienda real) y Stock → Tienda online.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'tienda-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/configuracion/tienda#canales`); await idle(); await page.waitForTimeout(500)
console.log('  tiendas:', (await page.innerText('[data-e2e="tiendas"]')).replace(/\s+/g, ' ').trim().slice(0, 200))
await shot('01-tiendas')
await page.click('[data-e2e="claves-shopify"]'); await page.fill('[data-e2e="clave-tienda"]', 'mitienda.com'); await page.fill('[data-e2e="clave-access_token"]', 'shpat_x'); await page.fill('[data-e2e="clave-secret"]', 's'); await page.click('[data-e2e="claves-guardar"]'); await page.waitForTimeout(1200)
console.log('  error claro:', (await page.locator('.text-carmin').last().innerText()).trim())
await shot('02-claves')
await page.goto(`${BASE}/stock/tienda-online`); await idle()
if (await page.locator('[data-e2e="sin-tiendas"]').isVisible().catch(() => false)) console.log('  sin tiendas:', (await page.innerText('[data-e2e="sin-tiendas"]')).replace(/\s+/g, ' ').trim().slice(0, 120))
else { console.log('  rubros:', (await page.innerText('[data-e2e="rubros"]')).replace(/\s+/g, ' ').trim().slice(0, 160)); await page.locator('[data-e2e^="rubro-"]').first().click(); await page.waitForTimeout(1200); console.log('  productos:', (await page.innerText('[data-e2e="productos"]')).replace(/\s+/g, ' ').trim().slice(0, 200)) }
await shot('03-tienda-online')
await fin(browser)
