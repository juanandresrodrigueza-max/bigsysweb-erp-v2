import { abrir, idle as _idle, shot as _shot, login as _login, salir, fin, BASE, OUT, errores } from './lib.mjs'
const { browser, page } = await abrir()
const PREFIX = 'producto-'
const idle = () => _idle(page); const shot = n => _shot(page, PREFIX + n)
const login = mail => _login(page, mail)

// 1. Tour en el primer ingreso
await login('demo@bigsys.com.ar')
await page.goto(`${BASE}/dashboard`); await idle(); await page.waitForTimeout(1200)
console.log('  tour visible:', await page.locator('text=Paso 1 de').count(), '·', (await page.locator('.fixed.z-\\[60\\] p.font-extrabold').first().textContent().catch(() => '')).trim())
await shot('01-tour')
for (let i = 0; i < 3; i++) { await page.click('button:has-text("Siguiente")'); await page.waitForTimeout(350) }
console.log('  paso 4:', (await page.locator('.fixed.z-\\[60\\] p.font-extrabold').first().textContent().catch(() => '')).trim())
await page.click('button:has-text("Saltar")'); await page.waitForTimeout(500)
await page.reload(); await idle(); await page.waitForTimeout(900)
console.log('  tour tras saltar:', await page.locator('text=Paso 1 de').count(), '(esperado 0)')

// 2. Ayuda contextual en Comprobantes → Nueva factura
await page.goto(`${BASE}/comprobantes/nuevo`); await idle()
await page.click('button[aria-label="Ayuda"]'); await page.waitForTimeout(700)
console.log('  panel:', (await page.locator('aside p.truncate').first().textContent()).trim(), '· h2:', (await page.locator('aside .ayuda-prose h2').first().textContent().catch(() => '')).trim())
await page.fill('aside input', 'anular'); await page.waitForTimeout(600)
console.log('  búsqueda:', (await page.locator('aside a p.font-semibold').allTextContents()).slice(0, 3).join(' | '))
await shot('02-ayuda-panel')
await page.keyboard.press('Escape'); await page.locator('aside button').first().click().catch(() => {})
// 3. Centro de ayuda
await page.goto(`${BASE}/ayuda/punto-de-venta`); await idle()
console.log('  centro:', (await page.locator('article h2').first().textContent()).trim(), '· artículos:', await page.locator('.card.p-2 a').count())
await shot('03-centro-ayuda')

// 4. Contador multiempresa: invitar el mismo email desde dos empresas
await page.goto(`${BASE}/contable/contador`); await idle()
await page.fill('input[placeholder="Nombre del contador"]', 'Estudio Gómez'); await page.fill('input[placeholder="Email"]', 'estudio@gomez.com'); await page.fill('input[placeholder^="Contraseña inicial"]', 'Estudio2026x')
await page.click('button:has-text("Dar acceso")'); await idle(); await page.waitForTimeout(400)
console.log('  invitado en empresa 1:', await page.locator('text=ya puede entrar').count())
await salir(page)
// segunda empresa (seed: otra empresa demo con su dueño)
await login('diego@ferreterianorte.com')
await page.goto(`${BASE}/contable/contador`); await idle()
await page.fill('input[placeholder="Email"]', 'estudio@gomez.com'); await page.click('button:has-text("Dar acceso")'); await idle(); await page.waitForTimeout(400)
console.log('  acceso desde empresa 2:', (await page.locator('.bg-emerald-50').first().textContent().catch(() => '')).trim().slice(0, 90))
console.log('  badge estudio:', await page.locator('text=estudio · 2 empresas').count())
await shot('04-contador-invitar')
// el contador entra y cambia de empresa
await salir(page); await _login(page, 'estudio@gomez.com', 'Estudio2026x')
console.log('  contador cae en:', page.url().replace(BASE, ''), '· empresas:', await page.locator('.card .font-extrabold.truncate').count())
await shot('05-mis-empresas')
await page.click('button[title="Cambiar de empresa"]'); await page.waitForTimeout(300)
const opciones = await page.locator('.absolute button .font-semibold').allTextContents(); console.log('  selector:', opciones.join(' | '))
await page.locator('.absolute button').nth(1).click(); await idle(); await page.waitForTimeout(500)
console.log('  ahora en:', (await page.locator('button[title="Cambiar de empresa"] span.font-semibold').textContent()).trim(), '·', (await page.locator('.bg-emerald-50').first().textContent().catch(() => '')).trim().slice(0, 60))
await shot('06-cambio-empresa')

// 5. API docs
await page.goto(`${BASE}/api/docs`); await idle()
console.log('  api docs:', (await page.locator('header h1').textContent()).trim(), '·', (await page.locator('header p').textContent()).trim().slice(0, 40), '· grupos:', await page.locator('section.grupo').count())
await shot('07-api-docs')

// 6. Métricas de uso (superadmin)
await salir(page); await login('super@bigsys.com.ar')
await page.goto(`${BASE}/admin/uso`); await idle()
console.log('  uso:', (await page.locator('.card p.text-xl').allTextContents()).map(t => t.trim()).join(' / '), '· ranking:', (await page.locator('table').last().locator('tbody tr td:first-child').allTextContents()).slice(0, 3).map(t => t.trim()).join(', '))
await shot('08-uso')
await fin(browser)
