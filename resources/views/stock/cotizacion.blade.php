@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $cant = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); @endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Cotización · {{ $b->name }}</title>
<style>
  body{font-family:DejaVu Sans,Arial,sans-serif;color:#111;font-size:12px;margin:0;padding:24px;background:#fff}
  .caja{max-width:720px;margin:0 auto}
  h1{font-size:20px;margin:0}.muted{color:#666}.aviso{border:1px solid #c33;color:#c33;padding:6px 10px;border-radius:6px;font-weight:bold;display:inline-block;margin:8px 0}
  table{width:100%;border-collapse:collapse;margin-top:12px}th{text-align:left;font-size:11px;text-transform:uppercase;color:#666;border-bottom:1px solid #999;padding:6px 4px}td{padding:6px 4px;border-bottom:1px solid #e5e5e5}.r{text-align:right;white-space:nowrap}
  .total{font-size:18px;font-weight:bold}
</style></head><body><div class="caja">
  <h1>{{ $b->name }}</h1>
  <div class="muted">{{ $b->razon_social }} @if($b->cuit)· CUIT {{ $b->cuit }}@endif @if($b->address)· {{ $b->address }}@endif</div>
  <div class="aviso">COTIZACIÓN · NO ES UN COMPROBANTE</div>
  <div>{{ $c->created_at->format('d/m/Y H:i') }} @if($c->cliente)· Para: <b>{{ $c->cliente }}</b>@endif · Lista {{ $c->lista }} · válida hasta {{ $c->expira_en?->format('d/m/Y') }}</div>
  <table><thead><tr><th>Artículo</th><th class="r">Cantidad</th><th class="r">Precio</th><th class="r">Importe</th></tr></thead><tbody>
    @foreach($c->items as $i)<tr><td>{{ $i['nombre'] }}</td><td class="r">{{ $cant($i['cantidad']) }} {{ $i['unit'] ?? '' }}</td><td class="r">{{ $fmt($i['precio']) }}</td><td class="r">{{ $fmt($i['cantidad'] * $i['precio']) }}</td></tr>@endforeach
  </tbody><tfoot><tr><td colspan="3" class="r total">TOTAL</td><td class="r total">{{ $fmt($c->total) }}</td></tr></tfoot></table>
  <p class="muted" style="margin-top:16px">Precios finales con IVA incluido, sujetos a cambio sin previo aviso. Esta cotización no genera obligación de venta ni constituye comprobante fiscal.</p>
  @unless($pdf)<p style="margin-top:12px"><a href="/q/{{ $c->token }}/pdf" style="background:#e4003f;color:#fff;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:bold">Descargar PDF</a></p>@endunless
</div></body></html>
