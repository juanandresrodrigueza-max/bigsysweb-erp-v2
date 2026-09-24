// Fase 25.1: precio pactado por cliente y artículo, descuento por rubro, y el formulario de venta que los aplica.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'pactados-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
// Primer cliente y primer artículo de la demo.
const cli = await (await page.request.get(`${BASE}/buscar/contactos/cliente?q=a`, { headers: { Accept: 'application/json' } })).json().catch(() => [])
const cliente = Array.isArray(cli) ? cli.find(c => c.id) : null
await page.goto(`${BASE}/clientes/${cliente.id}`); await idle()
await page.click('button:has-text("Precios pactados")'); await idle(); await page.waitForTimeout(400)
// Pacta un precio para un artículo.
const buscador = page.locator('[data-condiciones] input[placeholder="Buscar artículo…"]')
await buscador.click(); await buscador.fill('a'); await page.waitForTimeout(900)
const art = (await page.locator('[data-condiciones] .shadow-pop button').first().textContent()).trim().split(' · ')[0]
await page.locator('[data-condiciones] .shadow-pop button').first().dispatchEvent('mousedown')
await page.fill('[data-precio-pactado]', '777')
await page.click('[data-condiciones] button:has-text("Guardar")'); await idle(); await page.waitForTimeout(600)
// Descuento por rubro entero.
await page.click('[data-condiciones] label:has-text("Rubro entero")')
await page.locator('[data-rubro]').selectOption({ index: 1 })
await page.fill('[data-descuento-pactado]', '12')
await page.click('[data-condiciones] button:has-text("Guardar")'); await idle(); await page.waitForTimeout(600)
console.log('  condiciones en la ficha:', await page.locator('[data-condiciones] tbody tr').count(), '· artículo:', art)
await shot('01-ficha')
// En la factura el artículo sale al precio pactado con la marca "pactado".
await page.goto(`${BASE}/comprobantes/nuevo?tipo=FX`); await idle()
const cliInput = page.locator('input[placeholder^="Buscar cliente"]').first()
await cliInput.click(); await cliInput.fill(cliente.name.slice(0, 6)); await page.waitForTimeout(900)
await page.locator('.shadow-pop button', { hasText: cliente.name }).first().dispatchEvent('mousedown'); await page.waitForTimeout(800)
await page.click('button:has-text("Agregar")'); await page.waitForTimeout(200)
const artInput = page.locator('[data-fila="0"] input, input[placeholder*="Buscar artículo"]').first()
await artInput.click(); await artInput.fill(art.slice(0, 8)); await page.waitForTimeout(900)
console.log('  opción muestra pactado:', await page.locator('.shadow-pop button', { hasText: 'pactado' }).count() > 0)
await page.locator('.shadow-pop button', { hasText: art }).first().dispatchEvent('mousedown'); await page.waitForTimeout(400)
console.log('  precio en la línea:', await page.locator('[data-precio="0"]').inputValue(), '· marca:', (await page.locator('[data-origen-precio]').first().textContent().catch(() => '')).trim())
await shot('02-factura')
await fin(browser)
