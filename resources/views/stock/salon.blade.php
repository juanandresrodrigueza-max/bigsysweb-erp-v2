<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $pantalla['nombre'] }} · {{ $b->name }}</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;800;900&display=swap">
<style>
  *{box-sizing:border-box}html,body{height:100%;margin:0}body{font-family:Montserrat,system-ui,sans-serif;background:#4f3089;color:#fff;overflow:hidden;user-select:none;display:flex}
  #main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4vh 4vw;min-width:0;text-align:center}
  #cart{width:34vw;background:#000;position:relative;display:none}#cart img,#cart video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;display:none}
  h1{font-size:clamp(28px,6vh,64px);line-height:1.1;margin:0 0 1vh;text-wrap:balance}.precio{font-size:clamp(56px,16vh,200px);font-weight:900;line-height:1;letter-spacing:-.02em}
  .sub{font-size:clamp(16px,3vh,32px);opacity:.85}.promo{display:inline-block;margin-top:2vh;background:#ffcc33;color:#1b1430;font-weight:900;padding:.4em 1em;border-radius:999px;font-size:clamp(16px,3.5vh,40px)}
  img.foto{max-height:34vh;max-width:70%;object-fit:contain;border-radius:16px;background:rgba(255,255,255,.1);margin-bottom:2vh}
  .espera h1{font-size:clamp(28px,7vh,72px)}.espera p{font-size:clamp(16px,3vh,30px);opacity:.8}.barras{font-size:clamp(40px,10vh,100px);letter-spacing:.1em;opacity:.9}
  #estado{position:fixed;top:12px;right:16px;font-size:12px;opacity:.5}#salir{position:fixed;left:0;bottom:0;width:90px;height:90px}
  input#lector{position:absolute;opacity:0;width:1px;height:1px}
  .nohay h1{color:#ffd6d6}
</style></head>
<body>
<input id="lector" autofocus autocomplete="off">
<div id="main"><div class="espera"><div class="barras">▮▯▮▮▯▮</div><h1>Pasá el producto por el lector</h1><p>{{ $b->name }} · consultá el precio acá</p></div></div>
@if($cartelera)<div id="cart">@foreach($cartelera as $c)@if($c['tipo'] === 'imagen')<img src="{{ $c['url'] }}">@else<video src="{{ $c['url'] }}" muted loop playsinline autoplay></video>@endif @endforeach</div>@endif
<div id="estado"></div><div id="salir"></div>
<script>
// La pantalla del salón: baja el catálogo con su llave de sólo lectura, lo guarda en el dispositivo (anda sin internet) y lo refresca cada 10 minutos.
const TOKEN = @json($token), LISTA = @json($pantalla['lista']), SEG = @json($segundos), BZ = @json($balanza), KEY = 'salon_' + TOKEN.slice(0, 8)
let cat = []; try { cat = JSON.parse(localStorage.getItem(KEY) || '[]') } catch (e) {}
const estado = document.getElementById('estado'), main = document.getElementById('main'), lector = document.getElementById('lector')
const fmt = n => '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 })
async function bajar() { try { const r = await fetch('/salon/' + TOKEN + '/catalogo', { headers: { Accept: 'application/json' } }); if (r.status === 404) { estado.textContent = 'Esta pantalla fue dada de baja'; return } const j = await r.json(); cat = j.items; localStorage.setItem(KEY, JSON.stringify(cat)); estado.textContent = 'Precios al ' + new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }) + ' · ' + cat.length + ' artículos' } catch (e) { estado.textContent = cat.length ? 'Sin internet: mostrando lo último que bajó (' + cat.length + ')' : 'Sin internet y sin catálogo todavía' } }
bajar(); setInterval(bajar, 10 * 60 * 1000)
function balanza(t) { const pre = BZ.balanza_prefijo || '2'; if (!/^\d{13}$/.test(t) || !t.startsWith(pre)) return null; const dig = Math.min(6, Math.max(4, Number(BZ.balanza_digitos_plu || 5))); const plu = t.slice(pre.length, pre.length + dig); const val = Number(t.slice(pre.length + dig, pre.length + dig + 5)); const p = cat.find(x => x.plu && Number(x.plu) === Number(plu)) || cat.find(x => x.c === plu || Number(x.c) === Number(plu)); if (!p) return null; const dec = Number(BZ.balanza_decimales ?? 3); return BZ.balanza_modo === 'importe' ? { p, importe: val / Math.pow(10, dec), kg: null } : { p, kg: val / Math.pow(10, dec), importe: null } }
let timer = null
function mostrar(code) {
  code = code.trim(); if (!code) return
  const exact = cat.find(x => x.b === code || x.c === code); const bz = !exact ? balanza(code) : null
  const p = exact || bz?.p || cat.find(x => x.n.toLowerCase().includes(code.toLowerCase()))
  clearTimeout(timer)
  if (!p) { main.innerHTML = '<div class="nohay"><h1>No encontramos ese producto</h1><p class="sub">Código ' + code + ' · consultá en caja</p></div>'; timer = setTimeout(reposo, 8000); return }
  let precioTxt = fmt(p.p), sub = 'por ' + (p.u || 'unidad')
  if (bz?.kg) { precioTxt = fmt(Math.round(p.p * bz.kg * 100) / 100); sub = bz.kg.toLocaleString('es-AR') + ' kg a ' + fmt(p.p) + ' el kilo' }
  if (bz?.importe) { precioTxt = fmt(bz.importe); sub = (bz.importe / p.p).toFixed(3) + ' kg a ' + fmt(p.p) + ' el kilo' }
  main.innerHTML = (p.img ? '<img class="foto" src="' + p.img + '">' : '') + '<h1>' + p.n + '</h1><div class="precio">' + precioTxt + '</div><div class="sub">' + sub + '</div>' + (p.promo ? '<div class="promo">LLEVANDO ' + p.promo.min + ' O MÁS, ' + p.promo.pct + '% MENOS</div>' : '')
  timer = setTimeout(reposo, 15000)
}
function reposo() { main.innerHTML = '<div class="espera"><div class="barras">▮▯▮▮▯▮</div><h1>Pasá el producto por el lector</h1><p>' + @json($b->name) + ' · consultá el precio acá</p></div>' }
lector.addEventListener('keydown', e => { if (e.key === 'Enter') { mostrar(lector.value); lector.value = '' } })
document.addEventListener('click', () => lector.focus()); setInterval(() => { if (document.activeElement !== lector) lector.focus() }, 1500)
// La cartelera se turna sola
const cart = document.getElementById('cart'); if (cart) { cart.style.display = 'block'; const med = [...cart.children]; let i = 0; med[0].style.display = 'block'; if (med.length > 1) setInterval(() => { med[i].style.display = 'none'; i = (i + 1) % med.length; med[i].style.display = 'block' }, Math.max(3, SEG) * 1000) }
// Para salir: mantener apretada 3 segundos la esquina de abajo a la izquierda
let hold = null; const salir = document.getElementById('salir'); salir.addEventListener('pointerdown', () => { hold = setTimeout(() => { location.href = '/stock/consultor' }, 3000) }); salir.addEventListener('pointerup', () => clearTimeout(hold)); salir.addEventListener('pointerleave', () => clearTimeout(hold))
window.leerSalon = mostrar
</script></body></html>
