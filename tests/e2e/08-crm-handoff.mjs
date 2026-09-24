// Integración CRM · etapa 1: configuración y botón CRM en el menú del ERP.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'crm-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/configuracion/crm`); await idle()
console.log('  solapa CRM:', await page.locator('a:has-text("CRM")').count(), '· estado:', (await page.locator('.badge').first().textContent()).trim())
await page.fill('input[placeholder="https://crm.bigsysweb.com"]', 'https://crm.bigsysweb.com')
await page.click('button:has-text("Generar")'); await idle(); await page.waitForTimeout(500)
const secreto = (await page.locator('b.font-mono').first().textContent().catch(() => '')).trim()
console.log('  secreto generado:', secreto.length, 'caracteres')
await page.locator('label:has-text("Integración activa") input').check()
await page.click('button:has-text("Guardar")'); await idle(); await page.waitForTimeout(500)
console.log('  activa:', (await page.locator('.badge').first().textContent()).trim(), '· botón menú CRM:', await page.locator('nav a[href="/integraciones/crm/ir"]').count())
await shot('01-config')
// El botón redirige al CRM con un token (no seguimos la navegación externa: solo miramos el destino).
const r = await page.request.get(`${BASE}/integraciones/crm/ir?a=/contacts`, { maxRedirects: 0 })
console.log('  redirige a:', r.status(), (r.headers()['location'] ?? '').replace(/t=.*/, 't=…'))
// Entrada con token inválido: página clara de rechazo.
const r2 = await page.request.get(`${BASE}/integraciones/crm/entrar?t=abc.def`)
console.log('  rechazo:', r2.status(), (await r2.text()).includes('no se pudo entrar'))
await fin(browser)
