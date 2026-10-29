@php $per = $periodo ? \Carbon\Carbon::createFromFormat('Y-m', $periodo)->locale('es')->isoFormat('MMMM YYYY') : ''; @endphp
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu recibo · {{ $b->name }}</title>
<style>
  *{box-sizing:border-box} body{margin:0;background:#faf9f7;font-family:system-ui,sans-serif;color:#1c1a18;font-size:15px}
  .top{background:linear-gradient(135deg,#4f3089,#a42785);color:#fff;padding:16px} .top h1{margin:0;font-size:18px} .wrap{max-width:720px;margin:0 auto;padding:12px}
  .card{background:#fff;border:1px solid #e6e2dc;border-radius:16px;padding:14px;margin-bottom:12px} iframe{width:100%;height:65vh;border:1px solid #e6e2dc;border-radius:10px;background:#fff}
  canvas{width:100%;height:170px;border:1px dashed #b9b2a8;border-radius:10px;background:#fff;touch-action:none} .btn{display:block;width:100%;padding:13px;border:0;border-radius:12px;font-weight:700;font-size:15px;margin-top:8px;font-family:inherit}
  .p{background:#1f9d5b;color:#fff} .s{background:#fff;border:1px solid #d8d3cc} .ok{background:#e3f5ea;color:#146c3d} .muted{color:#6f6a62;font-size:12px} label{display:flex;gap:8px;align-items:center;margin-top:8px} textarea{width:100%;border:1px solid #d8d3cc;border-radius:10px;padding:8px;font-family:inherit}
</style></head><body>
<div class="top"><h1>{{ $b->name }}</h1><div style="opacity:.9;font-size:13px">Recibo de sueldo {{ $per }} · {{ $empleado?->nombre }}</div></div>
<div class="wrap">
  <div class="card"><iframe src="/recibo/{{ $token }}/ver" title="Recibo"></iframe><p class="muted"><a href="/recibo/{{ $token }}/ver" target="_blank">Abrir el recibo en otra pestaña</a></p></div>
  @if($r->firmado_en)
    <div class="card ok"><b>Firmado</b> el {{ $r->firmado_en->format('d/m/Y H:i') }}{{ $r->conformidad === 'disconforme' ? ' en disconformidad' : '' }}. Gracias.</div>
  @else
  <div class="card">
    @if($errors->any())<p style="color:#c8102e">{{ $errors->first() }}</p>@endif
    <form method="post" action="/recibo/{{ $token }}" onsubmit="return enviar()">@csrf
      <b>Firmá con el dedo</b>
      <canvas id="c" width="700" height="220"></canvas><button type="button" class="btn s" onclick="limpiar()">Borrar y firmar de nuevo</button>
      <label><input type="radio" name="conformidad" value="conforme" checked onchange="obs()"> Estoy conforme</label>
      <label><input type="radio" name="conformidad" value="disconforme" onchange="obs()"> Firmo en disconformidad</label>
      <textarea id="o" name="observacion" rows="2" placeholder="¿En qué no estás de acuerdo?" style="display:none;margin-top:6px"></textarea>
      <input type="hidden" name="firma" id="f">
      <button class="btn p">Firmar recibo</button>
      <p class="muted">Se guarda la fecha, la hora y desde dónde firmaste.</p>
    </form>
  </div>
  @endif
</div>
<script>
const c = document.getElementById('c'); let d = false, t = 0;
if (c) { const x = c.getContext('2d'); x.lineWidth = 3; x.lineCap = 'round';
  const pos = e => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height] };
  c.onpointerdown = e => { d = true; x.beginPath(); x.moveTo(...pos(e)); t++ }; c.onpointermove = e => { if (d) { x.lineTo(...pos(e)); x.stroke() } }; c.onpointerup = c.onpointerleave = () => d = false }
function limpiar() { c.getContext('2d').clearRect(0, 0, c.width, c.height); t = 0 }
function obs() { document.getElementById('o').style.display = document.querySelector('[name=conformidad]:checked').value === 'disconforme' ? 'block' : 'none' }
function enviar() { if (!t) { alert('Firmá en el recuadro.'); return false } document.getElementById('f').value = c.toDataURL('image/png'); return true }
</script>
</body></html>
