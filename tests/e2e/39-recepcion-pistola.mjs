// Fase 31.5: controlar la recepción con la pistola: lecturas por teclado, multiplicador, código que no está, conteo a mano,
// barra roja → amarilla → verde, cierre con diferencias renglón por renglón y el reclamo al proveedor en la compra y en Compras.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const CAJA = 'CAJA-' + (Date.now() % 100000)
const idle = () => _idle(page); const shot = n => _shot(page, 'recepcion-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})

// 1) una compra en borrador con tres renglones (la crea el formulario)
await page.goto(`${BASE}/proveedores/compras/nueva`); await idle()
const csrf = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content)
const r = await page.evaluate(async ({ BASE }) => {
  const res = await window.axios.post('/proveedores/compras', { contact_id: 7, tipo: 'FA', numero_proveedor: '0009-' + String(Date.now() % 100000000).padStart(8, '0'), fecha: new Date().toISOString().slice(0, 10), condicion: 'cta_cte', registrar: false,
    items: [{ product_id: 1, descripcion: 'Cemento x 50 kg', cantidad: 24, precio_unit: 9000, alicuota_iva: 21 }, { product_id: 2, descripcion: 'Hierro 8 mm x 12 m', cantidad: 5, precio_unit: 12000, alicuota_iva: 21 }, { product_id: 5, descripcion: 'Cal hidratada x 25 kg', cantidad: 4, precio_unit: 4000, alicuota_iva: 21 }] }, { validateStatus: () => true })
  return { status: res.status, url: res.request?.responseURL }
}, { BASE })
console.log('  compra creada:', r)
await page.goto(r.url.includes('/compras/') ? r.url : `${BASE}/proveedores/compras`); await idle()
if (!page.url().match(/compras\/\d+$/)) { await page.click('table tbody tr:first-child'); await idle() }
const compraUrl = page.url()
console.log('  compra:', compraUrl)
await shot('01-compra-boton')
console.log('  botón controlar:', await page.innerText('[data-e2e="controlar-recepcion"]'))

// 2) el control: la barra arranca en rojo
await page.click('[data-e2e="controlar-recepcion"]'); await idle()
console.log('  barra al abrir:', await page.getAttribute('[data-e2e="barra"]', 'data-avance'), '%')
await page.waitForTimeout(400); console.log('  foco en el lector:', await page.evaluate(() => document.activeElement?.tagName + ':' + (document.activeElement?.dataset?.e2e ?? document.activeElement?.className?.slice(0, 40))))
await page.click('h1'); await page.waitForTimeout(300); console.log('  foco vuelve tras tocar afuera:', await page.evaluate(() => document.activeElement?.tagName + ':' + (document.activeElement?.dataset?.e2e ?? document.activeElement?.className?.slice(0, 40))))
await shot('02-arranque')
// ×12 dos veces para el cemento
await page.click('[data-e2e="multiplicador"] button:has-text("×12")')
for (let i = 0; i < 2; i++) { await page.fill('[data-e2e="lector"]', '7790001000011'); await page.press('[data-e2e="lector"]', 'Enter'); await page.waitForTimeout(500) }
console.log('  tras 2×12 cemento:', (await page.innerText('[data-e2e="ultimo"]')).replace(/\s+/g, ' '))
console.log('  barra:', await page.getAttribute('[data-e2e="barra"]', 'data-avance'), '%')
// un código interno que no está: lo asigno al hierro
await page.click('[data-e2e="multiplicador"] button:has-text("×1")')
await page.fill('[data-e2e="lector"]', CAJA); await page.press('[data-e2e="lector"]', 'Enter'); await page.waitForTimeout(600)
await shot('03-no-esta')
await page.click('[data-e2e="pregunta-opciones"] button:has-text("Hierro")'); await page.waitForTimeout(500)
// y la segunda vez lo reconoce solo
await page.fill('[data-e2e="lector"]', CAJA); await page.press('[data-e2e="lector"]', 'Enter'); await page.waitForTimeout(500)
console.log('  aprendido:', (await page.innerText('[data-e2e="ultimo"]')).replace(/\s+/g, ' '))
// la cal se cuenta a mano: llegaron 2 de 4
const filaCal = page.locator('tr[data-e2e^="item-"]:has-text("Cal hidratada")')
await filaCal.locator('input').first().fill('2'); await filaCal.locator('input').first().press('Enter'); await page.waitForTimeout(500)
console.log('  estados:', await page.$$eval('tr[data-e2e^="item-"]', tr => tr.map(t => t.dataset.estado).join(',')))
await shot('04-contando')

// 3) terminar con diferencias: hierro pendiente, cal entra lo contado
await page.click('[data-e2e="terminar"]'); await page.waitForTimeout(400)
const selects = page.locator('select[data-e2e^="res-"]'); console.log('  renglones con diferencia:', await selects.count())
await selects.nth(0).selectOption('pendiente'); await selects.nth(1).selectOption('contado')
await shot('05-cierre')
await page.click('[data-e2e="confirmar-cierre"]'); await idle(); await page.waitForTimeout(500)
console.log('  vuelve a la compra:', page.url() === compraUrl, '· flash:', (await page.innerText('body')).includes('reclamo al proveedor'))
console.log('  tarjeta recepción:', (await page.innerText('[data-e2e="recepcion-card"]')).replace(/\s+/g, ' ').slice(0, 260))
await shot('06-compra-con-reclamo')

// 4) en Compras, el botón Reclamos con el contador
await page.goto(`${BASE}/proveedores/compras`); await idle()
console.log('  botón reclamos:', (await page.innerText('[data-e2e="reclamos"]')).replace(/\s+/g, ' '))
await page.click('[data-e2e="reclamos"]'); await page.waitForTimeout(700)
await shot('07-reclamos')
console.log('  modal:', (await page.innerText('[role=dialog], .fixed.inset-0')).replace(/\s+/g, ' ').slice(0, 260))

// 5) en el celular: el control cabe y la foto no estorba
const ctx2 = await browser.newContext({ viewport: { width: 430, height: 900 } }); const m = await ctx2.newPage()
await _login(m, 'demo@bigsys.com.ar'); await m.click('button:has-text("Saltar")').catch(() => {})
await m.goto(compraUrl + '/recepcion'); await _idle(m); await m.waitForTimeout(400)
console.log('  móvil scroll horizontal:', await m.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth))
await _shot(m, 'recepcion-08-movil')
await fin(browser)
