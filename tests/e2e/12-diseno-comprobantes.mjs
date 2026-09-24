// Fase 25.4: logo, colores y datos extra en los comprobantes, con vista previa.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'diseno-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/configuracion`); await idle()
// Datos fiscales obligatorios.
await page.fill('[data-domicilio]', 'Av. Colón 1234'); await page.fill('[data-iibb]', '901-123456-7'); await page.fill('[data-inicio]', '2015-03-01')
await page.click('button:has-text("Guardar cambios")'); await idle(); await page.waitForTimeout(500)
// Logo: un PNG chico generado en el navegador.
const png = await page.evaluate(() => { const c = document.createElement('canvas'); c.width = 240; c.height = 80; const x = c.getContext('2d'); x.fillStyle = '#0a5c36'; x.fillRect(0, 0, 240, 80); x.fillStyle = '#f2c200'; x.font = 'bold 34px sans-serif'; x.fillText('CORRALÓN', 18, 52); return c.toDataURL('image/png').split(',')[1] })
await page.setInputFiles('[data-logo-input]', { name: 'logo.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') }); await idle(); await page.waitForTimeout(1200)
// Colores, estilo y datos extra.
await page.locator('[data-diseno] input.font-mono').nth(0).fill('#0a5c36')
await page.locator('[data-diseno] input.font-mono').nth(1).fill('#f2c200')
await page.selectOption('[data-estilo]', 'banda')
await page.fill('[data-datos-extra]', 'www.corralondemo.com.ar\nAlias CORRALON.PAGOS')
await page.fill('[data-pie]', 'Gracias por su compra · Cambios dentro de los 30 días')
await page.waitForTimeout(300)
await page.locator('[data-preview]').scrollIntoViewIfNeeded()
await shot('01-config')
await page.click('[data-guardar-diseno]'); await idle(); await page.waitForTimeout(600)
console.log('  aviso:', (await page.locator('.bg-emerald-50').first().textContent().catch(() => '')).trim().slice(0, 60))
console.log('  logo en la vista previa:', await page.locator('[data-preview] img').count() > 0)
// La factura real con el diseño.
await page.goto(`${BASE}/configuracion/empresa/muestra`); await page.waitForTimeout(800)
console.log('  factura: banda verde', await page.locator('table.cab.banda').count() === 1, '· IIBB', await page.locator('text=Ingresos Brutos: 901-123456-7').count() === 1, '· pie', await page.locator('text=Gracias por su compra').count() === 1)
await shot('02-factura')
await fin(browser)
