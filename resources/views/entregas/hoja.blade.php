@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Hoja de reparto {{ $oe->numeroFormateado() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:12px}
  .hoja{max-width:800px;margin:0 auto;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden}
  .cab{display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid #d6d1ca}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:16px;font-weight:800;text-align:right}
  table{width:100%;border-collapse:collapse} th{text-align:left;font-size:10px;text-transform:uppercase;color:#6f6a62;padding:8px 10px;border-bottom:1px solid #d6d1ca;background:#f7f5f2} td{padding:10px;border-bottom:1px solid #eee;vertical-align:top} .r{text-align:right}
  .box{display:inline-block;width:14px;height:14px;border:1.5px solid #6f6a62;border-radius:3px;vertical-align:middle}
  .firma{display:flex;justify-content:space-between;padding:40px 16px 16px;font-size:10px;color:#6f6a62} .firma div{border-top:1px solid #6f6a62;width:30%;text-align:center;padding-top:4px}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  <div class="cab">
    <div><div class="emp">{{ $b->razon_social ?? $b->name }}</div><div>{{ $b->phone }}</div></div>
    <div><div class="tipo">HOJA DE REPARTO {{ $oe->numeroFormateado() }}</div><div class="r">{{ $oe->fecha->format('d/m/Y') }} · {{ $oe->repartidor ?? 'sin repartidor' }}@if($oe->vehiculo) · {{ $oe->vehiculo }} @endif </div></div>
  </div>
  <table>
    <tr><th style="width:24px">#</th><th>Cliente y dirección</th><th>Comprobante</th><th>Mercadería</th><th class="r">Cobrar</th><th style="width:70px">Entregado</th></tr>
    @foreach($oe->items as $n => $i)@php $c = $i->comprobante; @endphp
    <tr><td>{{ $n + 1 }}</td><td><b>{{ $c?->contact?->name }}</b><br>{{ $i->direccion }}@if($c?->contact?->phone || $c?->contact?->mobile)<br>Tel: {{ $c->contact->mobile ?: $c->contact->phone }} @endif </td>
      <td>{{ $c?->nombreTipo() }}<br>{{ $c?->numeroFormateado() }}</td>
      <td>{{ $c?->items->map(fn($x) => rtrim(rtrim(number_format((float) $x->cantidad, 3, ',', '.'), '0'), ',') . ' ' . $x->descripcion)->implode(' · ') }}</td>
      <td class="r">{{ $c && $c->condicion === 'contado' && $c->saldo > 0.005 ? $fmt($c->saldo) : '—' }}</td>
      <td><span class="box"></span> Sí &nbsp; <span class="box"></span> No</td></tr>
    @endforeach
  </table>
  @if($oe->notas)<div style="padding:12px 16px;border-top:1px solid #d6d1ca">{{ $oe->notas }}</div> @endif
  <div class="firma"><div>Salida depósito</div><div>Repartidor</div><div>Recepción</div></div>
</div>
</body>
</html>
