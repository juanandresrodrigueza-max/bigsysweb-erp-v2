// Fase 34.2–34.4: subir una lista fea (títulos de sección, encabezado repetido, precios con IVA), prepararla, cruzarla y ver lo raro destildado.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
import { writeFileSync } from 'node:fs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'importar-' + n)
const t = Date.now() % 100000
const csv = `Codigo;Articulo;Precio\nLISTA OCTUBRE;;\nDISCOS E2E;;\nE2E-D${t};Disco flap 115 e2e;1210\nE2E-D${t}B;Disco corte 230 e2e;2420\nCodigo;Articulo;Precio\nLIJAS E2E;;\nE2E-L${t};Lija al agua 220 e2e;0\nE2E-L${t};Lija al agua 220 e2e;605\n`
const f = `/tmp/lista-e2e-${t}.csv`; writeFileSync(f, csv)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/stock/importar`); await idle()
await page.selectOption('[data-e2e="proveedor"]', { index: 1 })
await page.setInputFiles('input[type=file]', f); await page.waitForSelector('[data-e2e="prep"]', { timeout: 15000 })
await page.evaluate(() => { document.querySelector('[data-e2e="prep"]').open = true }); await page.waitForTimeout(200)
await page.fill('[data-e2e="desde"]', '3'); await page.check('[data-e2e="con-iva"]'); await page.check('[data-e2e="titulos"]')
await page.check('[data-e2e="prep"] input[type=checkbox] >> nth=2').catch(() => {}) // quitar encabezado repetido
await shot('01-preparar')
await page.click('button:has-text("Cruzar con mi catálogo")'); await page.waitForSelector('[data-e2e="con-alerta"]', { timeout: 30000 })
console.log('  para revisar:', await page.innerText('[data-e2e="con-alerta"]'))
const filas = await page.locator('table tbody tr').allInnerTexts()
console.log('  filas:', filas.map(x => x.replace(/\s+/g, ' ').slice(0, 90)).join(' | '))
await shot('02-cruzada')
await page.click('button:has-text("Todas")'); await page.waitForTimeout(200)
await page.click('button:has-text("Importar (")'); await idle(); await page.waitForTimeout(1200)
const ok = await page.locator('.bg-emerald-50.border-emerald-200').first().innerText().catch(() => null) ?? await page.locator('[data-e2e="error-import"]').first().innerText().catch(() => '(sin mensaje)')
console.log('  importado:', ok.slice(0, 160))
await shot('03-importada')
// La próxima lista del mismo proveedor viene recordada
await page.goto(`${BASE}/stock/importar`); await idle()
await page.selectOption('[data-e2e="proveedor"]', { index: 1 })
await page.setInputFiles('input[type=file]', f); await page.waitForSelector('[data-e2e="recordado"]', { timeout: 15000 })
console.log('  recordado:', (await page.innerText('[data-e2e="recordado"]')).slice(0, 80))
console.log('  desde:', await page.inputValue('[data-e2e="desde"]'))
await shot('04-recordada')
await fin(browser)
