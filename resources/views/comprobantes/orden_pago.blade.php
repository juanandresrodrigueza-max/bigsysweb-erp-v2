@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Orden de pago {{ $p->numeroFormateado() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:12px}
  .hoja{max-width:700px;margin:0 auto;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden}
  .cab{display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid #d6d1ca}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:16px;font-weight:800;text-align:right}
  .sec{padding:12px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62}
  table{width:100%;border-collapse:collapse} td{padding:5px 0;border-bottom:1px solid #eee} .r{text-align:right}
  .tot{padding:14px 16px;text-align:right;font-size:16px;font-weight:800}
  .firma{display:flex;justify-content:space-between;padding:40px 16px 16px;font-size:10px;color:#6f6a62} .firma div{border-top:1px solid #6f6a62;width:40%;text-align:center;padding-top:4px}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  <div class="cab">
    <div><div class="emp">{{ $b->razon_social ?? $b->name }}</div><div>CUIT {{ $b->cuit }} · {{ $b->phone }}</div></div>
    <div><div class="tipo">ORDEN DE PAGO {{ $p->numeroFormateado() }}</div><div class="r">Fecha: {{ $p->fecha->format('d/m/Y') }}</div>@if($p->estado==='anulado')<div class="r" style="color:#e4003f;font-weight:800">ANULADA</div>@endif</div>
  </div>
  <div class="sec"><h4>Pagamos a</h4><b>{{ $p->contact->name }}</b> · {{ $p->contact->cuit ?? '' }}</div>
  <div class="sec"><h4>Medios de pago</h4><table>
    @foreach($p->medios as $m)<tr><td>{{ \App\Models\Pago::MEDIOS[$m->medio] ?? $m->medio }}@if($m->cheque) · cheque {{ $m->cheque->numero }} {{ $m->cheque->banco }} vto {{ $m->cheque->fecha_pago->format('d/m/Y') }}@endif{{ $m->referencia ? " · {$m->referencia}" : '' }}</td><td class="r">{{ $fmt($m->monto) }}</td></tr>@endforeach
  </table></div>
  @if($p->retenciones->count())
  <div class="sec"><h4>Retenciones practicadas</h4><table>
    @foreach($p->retenciones as $r)<tr><td>{{ \App\Models\Retencion::TIPOS[$r->tipo] ?? $r->tipo }} {{ $r->jurisdiccion }} · base {{ $fmt($r->base) }} · {{ $r->alicuota }}% @if($r->certificado)· cert. {{ $r->certificado }}@endif</td><td class="r">{{ $fmt($r->monto) }}</td></tr>@endforeach
  </table></div>
  @endif
  <div class="sec"><h4>Aplicado a</h4><table>
    @forelse($p->imputaciones as $i)<tr><td>{{ $i->comprobante->nombreTipo() }} {{ $i->comprobante->numeroFormateado() }} del {{ $i->comprobante->fecha->format('d/m/Y') }}</td><td class="r">{{ $fmt($i->monto) }}</td></tr>@empty<tr><td>Sin imputar</td><td></td></tr>@endforelse
    @if($p->a_cuenta > 0)<tr><td><i>A cuenta</i></td><td class="r">{{ $fmt($p->a_cuenta) }}</td></tr>@endif
  </table></div>
  <div class="tot">TOTAL {{ $fmt($p->total) }}</div>
  <div class="firma"><div>Recibí conforme</div><div>Autorizó</div></div>
</div>
</body>
</html>
