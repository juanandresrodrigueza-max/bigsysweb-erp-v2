// Fase 28: conectar una IA por MCP. Token personal desde la pantalla, llamadas JSON-RPC reales, propuesta y confirmación, y la pantalla de OAuth.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE, errores } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'mcp-' + n)
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/conexiones-ia`); await idle()
console.log('  URL MCP:', await page.inputValue('[data-e2e="mcp-url"]'))
await page.fill('[data-e2e="token-nombre"]', 'Prueba e2e'); await page.selectOption('[data-e2e="token-modo"]', 'acciones')
await page.click('[data-e2e="crear-token"]'); await idle(); await page.waitForTimeout(400)
const token = (await page.locator('[data-e2e="token-nuevo"] code').innerText()).trim()
console.log('  token creado:', token ? 'sí' : 'no')
await shot('01-conexiones')

const rpc = async (method, params = {}) => (await fetch(`${BASE}/mcp`, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }) })).json()
const tool = async (name, args = {}) => JSON.parse((await rpc('tools/call', { name, arguments: args })).result.content[0].text)
console.log('  initialize:', (await rpc('initialize', { protocolVersion: '2025-06-18' })).result.serverInfo.title)
console.log('  herramientas:', (await rpc('tools/list')).result.tools.length)
const v = await tool('ventas_periodo', { periodo: 'mes' }); console.log('  ventas del mes:', JSON.stringify(v).slice(0, 120))
const s = await tool('saldos_cuentas'); console.log('  cuentas:', s.cuentas?.length, '· total', s.total_pesos)
const p = await tool('registrar_gasto', { concepto: 'Prueba MCP e2e', monto: 1 }); console.log('  propuesta:', p.titulo)
const c = await tool('confirmar_accion', { propuesta_id: p.propuesta_id }); console.log('  confirmada:', c.ok ? 'sí' : JSON.stringify(c))
const d = await tool('transferir_entre_cuentas', { desde: 'caja', hasta: 'banco', monto: 1 }); console.log('  delicada en modo acciones:', d.error ? 'rechazada' : 'ACEPTADA (mal)')

await page.reload(); await idle(); await page.waitForTimeout(400)
console.log('  historial:', await page.locator('text=Prueba MCP e2e').count() > 0 ? 'muestra la acción' : 'no la muestra')
await shot('02-historial')

// OAuth: registro dinámico y pantalla de consentimiento.
const cli = await (await fetch(`${BASE}/oauth/register`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ client_name: 'Claude', redirect_uris: ['http://localhost:9/cb'] }) })).json()
const q = new URLSearchParams({ response_type: 'code', client_id: cli.client_id, redirect_uri: 'http://localhost:9/cb', code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', code_challenge_method: 'S256', state: 'e2e' })
await page.goto(`${BASE}/oauth/authorize?${q}`); await idle()
console.log('  consentimiento:', (await page.locator('[data-e2e="consentimiento"] h1').innerText()).trim())
await page.click('[data-e2e="modo-acciones"]')
await shot('03-consentimiento')
const [req] = await Promise.all([page.waitForRequest(r => r.url().startsWith('http://localhost:9/cb')), page.click('[data-e2e="permitir"]')])
console.log('  vuelve con código:', new URL(req.url()).searchParams.has('code') ? 'sí' : 'no', '· state', new URL(req.url()).searchParams.get('state'))
await page.waitForTimeout(1000)
await page.goto(`${BASE}/conexiones-ia`).catch(() => page.goto(`${BASE}/conexiones-ia`)); await idle()
for (const b of await page.locator('button:has-text("Desconectar")').all()) { await page.locator('button:has-text("Desconectar")').first().click(); await idle(); await page.waitForTimeout(300) }
console.log('  revocado: MCP responde', (await fetch(`${BASE}/mcp`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: '{"jsonrpc":"2.0","id":1,"method":"ping"}' })).status)
// El 409 es el aviso de Inertia para redirigir fuera del sitio (vuelta al cliente OAuth): es lo esperado.
for (let i = errores.length - 1; i >= 0; i--) if (errores[i].startsWith('http 409') && errores[i].includes('/oauth/authorize')) errores.splice(i, 1)
await fin(browser)
