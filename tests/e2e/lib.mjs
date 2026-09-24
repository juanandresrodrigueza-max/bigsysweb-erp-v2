// Utilidades compartidas de las pruebas de navegador. Corren contra un servidor con la base demo (php artisan migrate:fresh --seed).
import { chromium } from 'playwright-core'
import { mkdirSync } from 'node:fs'
export const BASE = process.env.E2E_BASE ?? 'http://127.0.0.1:8000'
export const OUT = process.env.E2E_OUT ?? new URL('./shots', import.meta.url).pathname
mkdirSync(OUT, { recursive: true })
export const errores = []
export async function abrir(viewport = { width: 1440, height: 900 }, extra = {}) {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox'] })
  const ctx = await browser.newContext({ viewport, ...extra })
  const page = await ctx.newPage()
  page.on('pageerror', e => errores.push('pageerror: ' + e.message)); page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon') && !r.url().includes('/mp/')) errores.push(`http ${r.status()} ${r.url()}`) })
  return { browser, ctx, page }
}
export const idle = page => page.waitForLoadState('networkidle')
export const shot = async (page, n) => { try { await page.screenshot({ path: `${OUT}/${n}.png`, timeout: 12000 }); console.log('shot', n) } catch (e) { console.log('shot', n, '(timeout)') } }
export async function login(page, mail, pass = 'password') { await page.goto(`${BASE}/login`); await idle(page); await page.fill('input[type=email]', mail); await page.fill('input[type=password]', pass); await page.click('button[type=submit]'); await idle(page); await page.waitForTimeout(800) }
export async function salir(page) { await page.goto(`${BASE}/dashboard`); await idle(page); await page.waitForTimeout(700); await page.click('button:has-text("Saltar")', { timeout: 1500 }).catch(() => {}); await page.click('aside button[title="Salir"]'); await idle(page) }
export function fin(browser) { return browser.close().then(() => { console.log('\nERRORES:', errores.length ? errores : 'ninguno'); if (errores.length) process.exitCode = 1 }) }
