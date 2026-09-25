// Fase 27.1: tablero de lotes y vencimientos, trazabilidad y retiro de un lote.
import { execSync } from 'node:child_process'
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
// Datos: un artículo perecedero de la demo con un lote por vencer y otro vencido.
execSync(`php artisan tinker --execute '$b=App\\Models\\Business::where("slug","!=","")->orderBy("id")->first(); auth()->login(App\\Models\\User::where("email","demo@bigsys.com.ar")->first()); $p=App\\Models\\Product::where("controla_stock",1)->orderBy("id")->first(); $p->update(["perecedero"=>true]); $d=App\\Models\\Deposito::porDefecto($p->business_location_id); $s=app(App\\Services\\Stock\\StockService::class); $s->entrada($p,20,"e2e",null,$d,100,null,["lote"=>"E2E-".date("His"),"vencimiento"=>now()->addDays(5)->toDateString()]); $s->entrada($p,4,"e2e",null,$d,100,null,["lote"=>"E2EV-".date("His"),"vencimiento"=>now()->subDays(2)->toDateString()]);'`, { stdio: 'ignore' })
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'lotes-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/stock/lotes`); await idle()
console.log('  lotes que requieren atención:', await page.locator('tbody tr').count(), '· vencidos:', (await page.locator('button:has-text("Vencidos con stock") p').nth(1).innerText()).trim())
await shot('01-tablero')
await page.fill('[data-e2e="buscar-lote"]', 'E2E-'); await page.keyboard.press('Enter'); await idle(); await page.waitForTimeout(400)
await page.locator('tbody tr').first().click(); await page.waitForTimeout(800)
console.log('  trazabilidad abierta:', await page.locator('[data-e2e="traza"]').count() === 1, '· movimientos:', await page.locator('[data-e2e="traza"] table').last().locator('tbody tr').count())
await page.click('[data-e2e="retirar"]'); await page.fill('.fixed input[placeholder^="Disposición"]', 'Prueba e2e'); await page.click('[data-e2e="confirmar-estado"]'); await idle(); await page.waitForTimeout(800)
console.log('  estado después del retiro:', (await page.locator('tbody tr').first().locator('.badge').last().innerText()).trim())
await shot('02-retiro')
await fin(browser)
