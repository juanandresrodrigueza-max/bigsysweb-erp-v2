@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $esp = $t->esperado_medios ?? []; $rend = $t->rendicion ?? []; $keys = array_unique(array_merge(array_keys($esp), array_keys($rend))); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Rendición de turno #{{ $t->id }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:12px}
  .hoja{max-width:720px;margin:0 auto;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden}
  .cab{display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid #d6d1ca}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:16px;font-weight:800;text-align:right}
  .sec{padding:12px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62}
  table{width:100%;border-collapse:collapse} th{text-align:left;font-size:10px;text-transform:uppercase;color:#6f6a62;padding:4px 0;border-bottom:1px solid #d6d1ca} td{padding:5px 0;border-bottom:1px solid #eee} .r{text-align:right} .neg{color:#e4003f;font-weight:700} .pos{color:#1f9d5b;font-weight:700}
  .firma{display:flex;justify-content:space-between;padding:40px 16px 16px;font-size:10px;color:#6f6a62} .firma div{border-top:1px solid #6f6a62;width:40%;text-align:center;padding-top:4px}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  <div class="cab">
    <div><div class="emp">{{ $b->razon_social ?? $b->name }}</div><div>{{ $t->cuenta->nombre }}@if($t->cuenta->location) · {{ $t->cuenta->location->name }} @endif </div></div>
    <div><div class="tipo">RENDICIÓN DE TURNO #{{ $t->id }}</div><div class="r">{{ $t->apertura->format('d/m/Y H:i') }} → {{ $t->cierre->format('d/m/Y H:i') }}</div><div class="r">Cajero: {{ $t->user?->name }}</div></div>
  </div>
  <div class="sec"><h4>Recaudación por medio de pago</h4><table>
    <tr><th>Medio</th><th class="r">Según sistema</th><th class="r">Declarado</th><th class="r">Diferencia</th></tr>
    @foreach($keys as $k)@php $e = (float) ($esp[$k] ?? 0); $d = $rend[$k] ?? null; $dif = $d === null ? null : round((float) $d - $e, 2); @endphp
    <tr><td>{{ $medios[$k] ?? ucfirst($k) }}@if($k==='efectivo') <small style="color:#6f6a62">(saldo de caja)</small> @endif </td><td class="r">{{ $fmt($e) }}</td><td class="r">{{ $d === null ? '—' : $fmt($d) }}</td><td class="r {{ $dif === null || abs($dif) < 0.005 ? '' : ($dif < 0 ? 'neg' : 'pos') }}">{{ $dif === null ? '' : $fmt($dif) }}</td></tr>
    @endforeach
    <tr><td><b>Efectivo: inicial / esperado / contado</b></td><td class="r">{{ $fmt($t->saldo_inicial) }} / {{ $fmt($t->saldo_esperado) }}</td><td class="r">{{ $fmt($t->saldo_contado) }}</td><td class="r {{ abs((float)$t->diferencia) < 0.005 ? '' : ((float)$t->diferencia < 0 ? 'neg' : 'pos') }}">{{ $fmt($t->diferencia) }}</td></tr>
  </table></div>
  <div class="sec"><h4>Movimientos de la caja en el turno</h4><table>
    <tr><th>Concepto</th><th>Origen</th><th class="r">Ingreso</th><th class="r">Egreso</th></tr>
    @forelse($movs as $m)<tr><td>{{ $m->concepto }}</td><td>{{ $m->origen }}</td><td class="r">{{ $m->ingreso > 0 ? $fmt($m->ingreso) : '' }}</td><td class="r">{{ $m->egreso > 0 ? $fmt($m->egreso) : '' }}</td></tr> @empty<tr><td colspan="4" style="color:#6f6a62">Sin movimientos.</td></tr> @endforelse
    <tr><td colspan="2"><b>Totales</b></td><td class="r"><b>{{ $fmt($movs->sum('ingreso')) }}</b></td><td class="r"><b>{{ $fmt($movs->sum('egreso')) }}</b></td></tr>
  </table></div>
  @if($t->stock)
  @php $cn = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); $imp = (float) $t->stock_importe; $fac = collect($t->stock)->sum(fn($f) => $f['facturado'] * $f['precio']); @endphp
  <div class="sec"><h4>Stock final del turno</h4><table>
    <tr><th>Artículo</th><th class="r">Inicial</th><th class="r">Entró</th><th class="r">Final</th><th class="r">Salió</th><th class="r">Facturado</th><th class="r">Sin facturar</th><th class="r">Importe</th></tr>
    @foreach($t->stock as $f)<tr><td>{{ $f['nombre'] }}</td><td class="r">{{ $cn($f['inicial']) }}</td><td class="r">{{ $cn($f['entradas'] - $f['otras_salidas']) }}</td><td class="r">{{ $cn($f['final']) }}</td><td class="r">{{ $cn($f['salio']) }}</td><td class="r">{{ $cn($f['facturado']) }}</td><td class="r" style="{{ abs($f['diferencia']) > 0.0005 ? 'color:#e4003f;font-weight:700' : '' }}">{{ $cn($f['diferencia']) }}</td><td class="r">{{ $fmt($f['importe']) }}</td></tr>@endforeach
    <tr><td colspan="7"><b>Salió por conteo</b> (facturado {{ $fmt($fac) }})</td><td class="r"><b>{{ $fmt($imp) }}</b></td></tr>
    <tr><td colspan="7"><b>Recaudado − stock</b></td><td class="r" style="{{ abs((float) $t->stock_diferencia) > 0.005 ? 'color:#e4003f' : 'color:#047857' }}"><b>{{ $fmt($t->stock_diferencia) }}</b></td></tr>
  </table></div>
  @endif
  @if($t->notas)<div class="sec"><h4>Notas</h4>{{ $t->notas }}</div> @endif
  <div class="firma"><div>Cajero</div><div>Responsable</div></div>
</div>
</body>
</html>
