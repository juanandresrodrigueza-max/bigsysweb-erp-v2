// Fase 40.5: activar avisos en el navegador, el parte de la mañana, la campanita de novedades y la página de mejoras.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, ctx, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'avisos-' + n)
await ctx.grantPermissions(['notifications'], { origin: BASE })
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/avisos-celular`); await idle()
console.log('  dispositivo:', (await page.innerText('[data-e2e="push"]')).replace(/\s+/g, ' ').slice(0, 90))
const act = page.locator('[data-e2e="push-activar"]')
if (await act.count()) { await act.click(); await page.waitForTimeout(3000); console.log('  después de activar:', (await page.innerText('[data-e2e="push"]')).replace(/\s+/g, ' ').slice(0, 140)) }
await page.fill('[data-e2e="manana-hora"]', '07:30'); await page.check('[data-e2e="manana"] input[type=checkbox] >> nth=0'); await page.click('[data-e2e="manana-guardar"]'); await idle()
console.log('  guardado:', (await page.locator('.bg-emerald-50').first().innerText().catch(() => '-')).slice(0, 90))
await page.click('[data-e2e="manana"] summary'); console.log('  parte:', (await page.innerText('[data-e2e="parte"]')).replace(/\s+/g, ' ').slice(0, 140))
await shot('01-celular')
await page.click('[data-e2e="novedades"]'); await page.waitForTimeout(800); console.log('  novedades:', await page.locator('[data-e2e="novedad"]').count()); await shot('02-novedades')
await page.goto(`${BASE}/mejoras`); await idle()
console.log('  mejoras:', await page.locator('[data-e2e="mejora"]').count())
const mi = page.locator('[data-e2e="me-interesa"]').first()
if (await mi.count()) { await mi.click(); await mi.click(); await idle(); console.log('  me interesa:', (await page.locator('.bg-emerald-50').first().innerText().catch(() => '-')).slice(0, 80)) }
await shot('03-mejoras')
await page.setViewportSize({ width: 360, height: 760 }); await page.goto(`${BASE}/avisos-celular`); await idle()
console.log('  360px sin scroll lateral:', await page.evaluate(() => document.documentElement.scrollWidth <= 361)); await shot('04-360')
await fin(browser)
