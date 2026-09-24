// Fase 25.5: el chofer anota lo cobrado en cada entrega y rinde el viaje con viáticos y diferencia.
import { execSync } from 'node:child_process'
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
// Hoja de reparto de la demo con dos facturas emitidas (se arma por consola para no depender de la pantalla de alta).
const id = execSync(`php artisan tinker --execute '$u = App\\Models\\User::where("email","demo@bigsys.com.ar")->first(); Auth::login($u); $ids = App\\Models\\Comprobante::ventas()->emitidos()->whereIn("tipo",["FA","FB"])->whereNotNull("contact_id")->where("saldo",">",0)->latest("id")->limit(2)->pluck("id")->all(); echo app(App\\Services\\Ventas\\EntregasService::class)->crearOrden(["comprobantes" => $ids, "repartidor" => "Juan Chofer", "vehiculo" => "Kangoo AB123CD"])->id;'`, { encoding: 'utf8' }).trim().split('\n').pop()
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'rendicion-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/comprobantes/entregas/${id}`); await idle()
// Primera entrega: efectivo por lo que se sugiere; segunda: un cheque.
await page.locator('[data-anotar-cobro]').nth(0).click(); await page.waitForTimeout(300)
await page.fill('[data-monto-cobro]', '50000')
await page.click('[data-guardar-cobro]'); await idle(); await page.waitForTimeout(500)
await page.locator('[data-anotar-cobro]').nth(1).click(); await page.waitForTimeout(300)
await page.click('label:has-text("Cheque")'); await page.fill('[data-monto-cobro]', '20000')
await page.locator('.fixed input.input').nth(1).fill('Galicia'); await page.locator('.fixed input.input').nth(2).fill('778899')
await page.click('[data-guardar-cobro]'); await idle(); await page.waitForTimeout(500)
for (let k = 0; k < 2; k++) { await page.locator('[data-entregado]').first().click(); await idle(); await page.waitForTimeout(500) }
await shot('01-hoja')
// Rendir: 1.500 de nafta, entrega 48.400 → faltan 100.
await page.click('[data-rendir]'); await page.waitForTimeout(300)
await page.click('button:has-text("+ Viático")'); await page.fill('[data-viatico="0"]', 'Nafta'); await page.fill('[data-viatico-monto="0"]', '1500')
await page.fill('[data-contado]', '48400'); await page.waitForTimeout(200)
console.log('  diferencia en pantalla:', (await page.locator('[data-diferencia]').textContent()).trim())
await shot('02-rendir')
await page.click('[data-confirmar-rendir]'); await idle(); await page.waitForTimeout(800)
console.log('  aviso:', (await page.locator('.bg-emerald-50').first().textContent().catch(() => '')).trim().slice(0, 80))
console.log('  rendida:', await page.locator('[data-rendida]').count() === 1, '· resumen:', await page.locator('[data-resumen-rendicion]').count() === 1)
await shot('03-rendida')
await fin(browser)
