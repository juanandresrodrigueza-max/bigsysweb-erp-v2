// Fase 26.6: documentos adjuntos en la ficha del cliente y del artículo.
import { abrir, idle as _idle, shot as _shot, login as _login, fin, BASE } from './lib.mjs'
import { writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
const { browser, page } = await abrir()
const idle = () => _idle(page); const shot = n => _shot(page, 'doc-' + n)
const PDF = `${tmpdir()}/constancia.pdf`; writeFileSync(PDF, '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')
await _login(page, 'demo@bigsys.com.ar')
await page.click('button:has-text("Saltar")').catch(() => {})
await page.goto(`${BASE}/clientes`); await idle()
await page.locator('tbody tr').first().click(); await idle()
await page.click('button:has-text("Documentos")'); await page.waitForTimeout(500)
const antes = await page.locator('[data-e2e="documentos"] a:has-text("bajar")').count()
await page.setInputFiles('[data-e2e="subir-doc"]', PDF); await page.waitForTimeout(1500)
console.log('  documentos del cliente:', antes, '→', await page.locator('[data-e2e="documentos"] a:has-text("bajar")').count())
await shot('01-cliente')
await page.goto(`${BASE}/stock/1`); await idle()
await page.setInputFiles('[data-e2e="subir-doc"]', PDF); await page.waitForTimeout(1500)
console.log('  documento en el artículo:', await page.locator('[data-e2e="documentos"] a', { hasText: 'constancia' }).count() >= 1)
await page.locator('[data-e2e="documentos"]').scrollIntoViewIfNeeded(); await shot('02-articulo')
await fin(browser)
