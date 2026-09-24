// Integración CRM · etapa 2: pantalla de configuración con sincronización y webhook entrante.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'crm-sync-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/configuracion/crm`); await idle()
// Deja la integración activa (URL + secreto generado) y una clave de API cualquiera.
await page.fill('input[placeholder="https://crm.bigsysweb.com"]', 'https://crm.bigsysweb.com')
await page.click('button:has-text("Generar")'); await idle(); await page.waitForTimeout(400)
await page.locator('input[placeholder*="La creás en el CRM"], input[placeholder*="(cargada)"]').first().fill('bsk_demo')
await page.locator('label:has-text("Integración activa") input').check()
await page.click('button:has-text("Guardar")'); await idle(); await page.waitForTimeout(400)
console.log('  botón sincronizar:', await page.locator('button:has-text("Sincronizar clientes y artículos")').count(), '· paso 4 explica dueño de datos:', await page.locator('text=Lo fiscal (CUIT, condición IVA').count())
const urlWebhook = (await page.locator('code:has-text("/api/crm/webhook/")').first().textContent()).trim()
console.log('  url webhook:', urlWebhook.replace(BASE, ''))
await page.click('button:has-text("Sincronizar clientes y artículos")'); await idle(); await page.waitForTimeout(500)
console.log('  encolado:', (await page.locator('.bg-emerald-50, .bg-carmin-light').first().textContent().catch(() => '')).trim().slice(0, 80))
await shot('01-config-sync')
// Webhook sin firma → 401 (la firma se prueba en las pruebas automáticas).
const r = await page.request.post(urlWebhook, { data: { event: 'contact.created', data: { contact: { id: 1, name: 'X' } } } })
console.log('  webhook sin firma:', r.status())
await fin(browser)
