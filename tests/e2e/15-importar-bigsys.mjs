// Fase 25.6: importar la tabla de cuentas de BigSys Clarion con el perfil, y encontrar al cliente por su código viejo.
import { writeFileSync } from 'node:fs'
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'bigsys-' + n)
const cod = String(5000 + Math.floor(Math.random() * 4000))
const csv = `codcta;tipcta;nombre;nroiva;codiva;direcc;locali;lispre;pordes\n${cod};C;Almacen El Legado;20-36416926-5;RI;San Martin 45;Rio Cuarto;3;5\n${Number(cod) + 1};E;Empleado que no va;;;;;;\n`
writeFileSync('/tmp/cta_bigsys.csv', Buffer.from(csv, 'latin1'))
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/configuracion/importar`); await idle()
await page.locator('select').first().selectOption('clientes').catch(() => {})
await page.locator('select:has(option[value="bigsys"])').selectOption('bigsys')
await page.setInputFiles('input[type=file]', '/tmp/cta_bigsys.csv')
await page.click('button:has-text("Leer archivo")'); await idle(); await page.waitForTimeout(700)
const mapeo = await page.locator('thead select').evaluateAll(els => els.map(e => e.value))
console.log('  mapeo:', mapeo.join(','))
console.log('  aviso BigSys:', await page.locator('text=se importan solo los de tipo C').count() === 1)
await shot('01-preview')
await page.click('button:has-text("Importar")'); await idle(); await page.waitForTimeout(800)
console.log('  historial:', (await page.locator('tbody tr').first().textContent().catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 120))
await shot('02-historial')
// Buscador global por el código de BigSys.
const r = await page.request.get(`${BASE}/buscar/global?q=${cod}`, { headers: { Accept: 'application/json' } })
const j = await r.json(); console.log('  buscar por código', cod, '→', j.clientes?.[0]?.titulo)
await fin(browser)
