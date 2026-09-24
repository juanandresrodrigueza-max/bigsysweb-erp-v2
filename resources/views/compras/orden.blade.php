@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $cant = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Orden de compra {{ $oc->numeroFormateado() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:12px}
  .hoja{max-width:760px;margin:0 auto;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden}
  .cab{display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid #d6d1ca}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:16px;font-weight:800;text-align:right}
  .sec{padding:12px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62}
  table{width:100%;border-collapse:collapse} th{text-align:left;font-size:10px;text-transform:uppercase;color:#6f6a62;padding:4px 0;border-bottom:1px solid #d6d1ca} td{padding:6px 0;border-bottom:1px solid #eee} .r{text-align:right}
  .tot{padding:14px 16px;text-align:right;font-size:16px;font-weight:800}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  <div class="cab">
    <div><div class="emp">{{ $b->razon_social ?? $b->name }}</div><div>CUIT {{ $b->cuit }} · {{ $b->phone }} · {{ $b->email }}</div>@if($oc->location)<div>{{ $oc->location->name }} · {{ $oc->location->address }}</div>@endif </div>
    <div><div class="tipo">ORDEN DE COMPRA {{ $oc->numeroFormateado() }}</div><div class="r">Fecha: {{ $oc->fecha->format('d/m/Y') }}</div>@if($oc->fecha_entrega)<div class="r">Entrega esperada: {{ $oc->fecha_entrega->format('d/m/Y') }}</div>@endif @if($oc->estado==='cancelada')<div class="r" style="color:#e4003f;font-weight:800">CANCELADA</div>@endif </div>
  </div>
  <div class="sec"><h4>Proveedor</h4><b>{{ $oc->contact->name }}</b> · {{ $oc->contact->cuit ?? '' }}@if($oc->contact->email) · {{ $oc->contact->email }}@endif </div>
  <div class="sec"><h4>Artículos</h4><table>
    <tr><th>Descripción</th><th class="r">Cantidad</th><th class="r">Precio neto</th><th class="r">Subtotal</th></tr>
    @foreach($oc->items as $i)<tr><td>{{ $i->descripcion }}@if($i->product?->sku) <span style="color:#6f6a62">({{ $i->product->sku }})</span>@endif @if($i->notas)<br><small style="color:#6f6a62">{{ $i->notas }}</small>@endif</td><td class="r">{{ $cant($i->cantidad) }} {{ $i->product?->unit }}</td><td class="r">{{ $fmt($i->precio_unit) }}</td><td class="r">{{ $fmt($i->cantidad * $i->precio_unit) }}</td></tr>@endforeach
  </table></div>
  @if($oc->notas)<div class="sec"><h4>Condiciones</h4>{!! nl2br(e($oc->notas)) !!}</div>@endif
  <div class="tot">Total neto {{ $fmt($oc->total) }} <span style="font-size:10px;font-weight:400;color:#6f6a62">(IVA no incluido)</span></div>
</div>
</body>
</html>
