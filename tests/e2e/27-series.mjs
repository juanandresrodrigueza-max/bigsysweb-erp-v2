// Fase 27.2: números de serie: ficha del equipo, service en garantía y venta en el punto de venta leyendo la serie.
import { execSync } from 'node:child_process'
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const sn = 'E2E' + Date.now().toString().slice(-6)
execSync(`php artisan tinker --execute 'auth()->login(App\\Models\\User::where("email","demo@bigsys.com.ar")->first()); $p=App\\Models\\Product::where("controla_stock",1)->orderByDesc("id")->first(); $p->update(["seriado"=>true,"perecedero"=>false,"garantia_meses"=>12]); $d=App\\Models\\Deposito::porDefecto(auth()->user()->current_location_id); app(App\\Services\\Stock\\StockService::class)->entrada($p,2,"e2e",null,$d,100,null,["serie"=>"${sn}A,${sn}B"]);'`, { stdio: 'ignore' })
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'series-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/stock/series`); await idle()
await page.fill('[data-e2e="buscar-serie"]', sn + 'A'); await page.press('[data-e2e="buscar-serie"]', 'Enter'); await idle(); await page.waitForTimeout(900)
console.log('  ficha del equipo:', await page.locator('[data-e2e="ficha-serie"]').count() === 1)
await shot('01-ficha')
// Punto de venta: se lee la etiqueta de la serie B.
await page.goto(`${BASE}/retail`); await idle()
const q = page.locator('input[placeholder^="Código de barras"]').first()
await q.fill(sn + 'B'); await q.press('Enter'); await page.waitForTimeout(1200)
console.log('  serie en el ticket:', await page.locator('[data-e2e="pos-serie"]').first().inputValue().catch(() => '—'))
await shot('02-pos')
// Service desde la ficha de la serie A.
await page.goto(`${BASE}/stock/series?buscar=${sn}A`); await idle(); await page.waitForTimeout(900)
await page.fill('[data-e2e="falla"]', 'No enciende'); await page.click('[data-e2e="crear-servicio"]'); await idle(); await page.waitForTimeout(800)
console.log('  orden de servicio creada:', page.url().includes('/servicios/'))
await shot('03-servicio')
await fin(browser)
