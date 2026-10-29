// Fase 36.1/36.3/36.4: qué reparás, nueva orden con los datos del rubro, hoja, el cliente aprueba ítem por ítem desde el link, qué sigue, esperando, mis órdenes y pago a técnicos.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'taller-' + n)
page.on('dialog', d => d.accept(d.type() === 'prompt' ? 'Repuesto pedido' : undefined))
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/servicios`); await idle()
if (await page.locator('[data-rubro="taller"]').count()) { await page.click('[data-rubro="taller"]'); await idle() }
const t = Date.now() % 100000
await page.click('[data-e2e="nueva"]'); await page.waitForTimeout(300)
await page.locator('.fixed select').first().selectOption({ index: 1 })
await page.fill('[data-e2e="equipo"]', `Gol E2E ${t}`); await page.fill('[data-e2e="serie"]', `E2E${t}`); await page.locator('.fixed textarea').first().fill('Ruido al frenar')
console.log('  labels:', (await page.locator('.fixed .label').allInnerTexts()).join(' | '))
await shot('01-nueva')
await page.locator('.fixed .btn-primary').last().click(); await idle(); await page.waitForURL(/servicios\/\d+/)
console.log('  qué sigue:', await page.innerText('[data-e2e="que-sigue"]'))
const id = page.url().split('/').pop()
for (const [d, p] of [['Pastillas', '30000'], ['Correa', '90000']]) { await page.fill('input[placeholder^="o describilo"]', d); await page.fill('input[placeholder="Precio"]', p); await page.click('button:has-text("Agregar")'); await idle(); await page.waitForTimeout(300) }
await page.click('button:has-text("Enviar presupuesto")').catch(() => {}); await idle()
// El cliente abre el link y destilda la correa
const url = await page.locator('a[href*="/ot/"]').first().getAttribute('href')
const cli = await browser.newPage(); await cli.goto(url); await cli.locator('input[name="aprobados[]"]').nth(1).uncheck()
console.log('  total cliente:', await cli.innerText('#total'))
await cli.click('button[value="aprobar"]'); await cli.waitForLoadState('networkidle'); console.log('  cliente ve:', (await cli.innerText('.card.ok')).replace(/\s+/g, ' ').slice(0, 140)); await cli.close()
await page.reload(); await idle()
console.log('  aprobación:', (await page.locator('text=El cliente aprobó').innerText()).slice(0, 120))
await page.click('[data-e2e="esperar"]'); await idle(); await page.waitForTimeout(400)
console.log('  esperando:', await page.innerText('[data-e2e="que-sigue"]'))
await shot('02-orden')
await page.goto(`${BASE}/servicios/mis-ordenes`); await idle(); console.log('  mis órdenes:', await page.locator('[data-e2e="mis-ordenes"] > a').count())
await page.goto(`${BASE}/servicios/tecnicos`); await idle(); console.log('  pago:', (await page.innerText('[data-e2e="pago"]')).replace(/\s+/g, ' ').slice(0, 120))
await shot('03-pago')
await fin(browser)
