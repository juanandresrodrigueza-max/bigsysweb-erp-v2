// Corre todas las pruebas de navegador en orden. Requiere el servidor levantado con la base demo recién sembrada.
// Local:  php artisan migrate:fresh --seed && php artisan serve &  CHROMIUM_PATH=/ruta/a/chromium node tests/e2e/run.mjs
import { readdirSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
const dir = new URL('./', import.meta.url).pathname
const scripts = readdirSync(dir).filter(f => f.endsWith('.mjs') && !['run.mjs', 'lib.mjs'].includes(f)).sort()
let fallas = 0
for (const s of scripts) {
  console.log(`\n=== ${s} ===`)
  const r = spawnSync(process.execPath, [dir + s], { stdio: 'inherit', env: process.env })
  if (r.status !== 0) { fallas++; console.log(`--- ${s}: FALLÓ (código ${r.status})`) }
}
console.log(fallas ? `\n${fallas} script(s) con errores` : '\nTodo OK')
process.exit(fallas ? 1 : 0)
