@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen de cuenta · {{ $c->name }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:11px}
  .hoja{max-width:760px;margin:0 auto;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden}
  .cab{display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid #d6d1ca}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:15px;font-weight:800;text-align:right}
  .sec{padding:12px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62}
  table{width:100%;border-collapse:collapse} th{text-align:left;font-size:9px;text-transform:uppercase;color:#6f6a62;padding:4px 0;border-bottom:1px solid #d6d1ca} td{padding:4px 0;border-bottom:1px solid #eee} .r{text-align:right} .neg{color:#e4003f;font-weight:700}
  .kpi{display:flex;gap:16px;padding:12px 16px;border-bottom:1px solid #d6d1ca} .kpi div{flex:1;background:#f2efea;border-radius:8px;padding:8px 10px} .kpi b{display:block;font-size:15px}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  <div class="cab">
    <div><div class="emp">{{ $b->razon_social ?? $b->name }}</div><div>CUIT {{ $b->cuit }} · {{ $b->phone }} · {{ $b->email }}</div></div>
    <div><div class="tipo">RESUMEN DE CUENTA</div><div class="r">Al {{ today()->format('d/m/Y') }}</div></div>
  </div>
  <div class="sec"><h4>Cliente</h4><b>{{ $c->name }}</b> · {{ $c->cuit ?? $c->document ?? '' }} · {{ $c->condicion_iva }}@if($c->address)<br>{{ $c->address }} {{ $c->city }} @endif </div>
  <div class="kpi"><div>Saldo actual<b class="{{ $saldo > 0 ? 'neg' : '' }}">{{ $fmt($saldo) }}</b></div><div>Vencido<b class="{{ $vencido > 0 ? 'neg' : '' }}">{{ $fmt($vencido) }}</b></div><div>Comprobantes pendientes<b>{{ $pendientes->count() }}</b></div></div>
  @if($pendientes->count())
  <div class="sec"><h4>Pendientes de pago</h4><table>
    <tr><th>Comprobante</th><th>Fecha</th><th>Vencimiento</th><th class="r">Total</th><th class="r">Saldo</th></tr>
    @foreach($pendientes as $p)<tr><td>{{ $p->nombreTipo() }} {{ $p->numeroFormateado() }}</td><td>{{ $p->fecha->format('d/m/Y') }}</td><td class="{{ $p->vencido() ? 'neg' : '' }}">{{ $p->fecha_vto?->format('d/m/Y') }}</td><td class="r">{{ $fmt($p->total) }}</td><td class="r">{{ $fmt($p->saldo) }}</td></tr> @endforeach
  </table></div>
  @endif
  <div class="sec"><h4>Últimos movimientos</h4><table>
    <tr><th>Fecha</th><th>Concepto</th><th class="r">Debe</th><th class="r">Haber</th><th class="r">Saldo</th></tr>
    @foreach($filas as $f)<tr><td>{{ $f['fecha'] }}</td><td>{{ $f['concepto'] }}</td><td class="r">{{ $f['debe'] ? $fmt($f['debe']) : '' }}</td><td class="r">{{ $f['haber'] ? $fmt($f['haber']) : '' }}</td><td class="r">{{ $fmt($f['saldo']) }}</td></tr> @endforeach
  </table></div>
</div>
</body>
</html>
