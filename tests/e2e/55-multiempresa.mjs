// Fase 39: perfiles listos en usuarios, el estudio (precio y vencimientos), el semáforo y los conjuntos de empresas.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'multi-' + n)
await _login(page, 'demo@bigsys.com.ar'); await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/configuracion/usuarios`); await idle()
const editar = page.locator('button:has-text("Editar"), [title="Editar"]').first()
if (await editar.count()) { await editar.click(); await page.waitForTimeout(300); console.log('  perfiles:', (await page.locator('[data-perfil]').allInnerTexts()).map(t => t.split('\n')[0]).join(' | ')); await page.click('[data-perfil="solo_mirar"]').catch(() => {}); await shot('01-usuario') }
await page.goto(`${BASE}/contador/empresas`); await idle()
console.log('  estudio:', (await page.innerText('[data-e2e="estudio"]')).replace(/\s+/g, ' ').slice(0, 140))
console.log('  semáforos:', await page.locator('[data-e2e="semaforo"]').count())
console.log('  vencimientos:', (await page.innerText('[data-e2e="vencimientos"]')).replace(/\s+/g, ' ').slice(0, 140))
await shot('02-empresas')
const marcas = page.locator('[data-e2e="conjuntos"] input[type=checkbox]')
if (await marcas.count() >= 2) {
  await page.fill('[data-e2e="nombre-conjunto"]', 'Todo el grupo e2e'); await marcas.nth(0).check(); await marcas.nth(1).check(); await page.click('[data-e2e="crear-conjunto"]'); await idle()
  console.log('  conjunto:', (await page.innerText('[data-e2e="total"]')).replace(/\s+/g, ' ').slice(0, 160)); await shot('03-conjunto')
} else console.log('  (la demo tiene una sola empresa)')
await fin(browser)
