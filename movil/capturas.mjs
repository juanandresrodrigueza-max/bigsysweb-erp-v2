// Fase 93.1: las capturas para las tiendas, desde la demo. Genera iPhone 6,7" (1290×2796) y Android (1080×2400).
// Uso: php artisan migrate:fresh --seed && php artisan serve &  CHROMIUM_PATH=/ruta/chromium node movil/capturas.mjs [carpeta de salida]
import { chromium } from 'playwright-core'
import { mkdirSync } from 'node:fs'
const BASE = process.env.E2E_BASE ?? 'http://127.0.0.1:8000', OUT = process.argv[2] ?? 'movil/tiendas/capturas'
mkdirSync(OUT, { recursive: true })
const br = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox'] })
const tam = { iphone: { w: 430, h: 932, s: 3 }, android: { w: 360, h: 800, s: 3 } }
const pantallas = [
  { n: '01-mi-negocio', u: '/dueno', user: 'demo@bigsys.com.ar' },
  { n: '02-mozo-de-bolsillo', u: '/gastronomia/mozo', user: 'mariela@laespiga.com.ar' },
  { n: '03-vender', u: '/vender', user: 'demo@bigsys.com.ar' },
  { n: '04-alertas', u: '/alertas', user: 'demo@bigsys.com.ar' },
  { n: '05-bigcito', u: '/dashboard', user: 'demo@bigsys.com.ar', bigcito: true },
  { n: '06-salon', u: '/gastronomia', user: 'mariela@laespiga.com.ar' },
]
for (const [disp, t] of Object.entries(tam)) {
  // Un contexto (sesión) por usuario: así no hace falta cerrar sesión entre capturas.
  const ctxs = {}
  const sesion = async user => {
    if (ctxs[user]) return ctxs[user]
    const ctx = await br.newContext({ viewport: { width: t.w, height: t.h }, deviceScaleFactor: t.s, isMobile: true, hasTouch: true, userAgent: disp === 'iphone' ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/124 Mobile Safari/537.36' })
    const pg = await ctx.newPage()
    // La captura se saca "adentro de la app": sin barra de instalar ni consejos, con la clase es-app.
    await pg.addInitScript(() => { window.Capacitor = { isNativePlatform: () => true, getPlatform: () => 'ios' } })
    await pg.goto(BASE + '/login'); await pg.fill('input[type=email]', user); await pg.fill('input[type=password]', 'password'); await pg.click('button[type=submit]'); await pg.waitForURL(u => !u.pathname.includes('login')); await pg.click('button:has-text("Saltar")').catch(() => {})
    return (ctxs[user] = pg)
  }
  for (const p of pantallas) {
    const pg = await sesion(p.user)
    await pg.goto(BASE + p.u); await pg.waitForTimeout(1500)
    await pg.evaluate(() => { document.querySelectorAll('[data-tip], [data-e2e=instalar-app]').forEach(e => e.remove()) })
    if (p.bigcito) { await pg.locator('[data-tour=asistente] button').first().click({ force: true }); await pg.waitForTimeout(800) }
    await pg.screenshot({ path: `${OUT}/${disp}-${p.n}.png` })
    console.log('✓', disp, p.n)
  }
  for (const pg of Object.values(ctxs)) await pg.context().close()
}
await br.close()
