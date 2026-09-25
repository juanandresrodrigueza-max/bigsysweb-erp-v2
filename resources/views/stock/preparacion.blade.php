@php $n = fn($x) => rtrim(rtrim(number_format((float) $x, 3, ',', '.'), '0'), ','); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Preparación {{ $r['comprobante']['nombre'] }}</title>
<style>
  *{box-sizing:border-box} body{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:20px;font-size:12px}
  .hoja{max-width:760px;margin:0 auto} h1{font-size:18px;margin:0} .mut{color:#6f6a62}
  table{width:100%;border-collapse:collapse;margin-top:12px} th{font-size:10px;text-transform:uppercase;letter-spacing:.05em;text-align:left;border-bottom:2px solid #1c1a18;padding:6px 4px} td{padding:8px 4px;border-bottom:1px solid #ddd;vertical-align:top}
  .ubic{font-size:16px;font-weight:bold;font-family:monospace} .r{text-align:right} .check{width:22px;height:22px;border:2px solid #1c1a18;border-radius:4px}
  .falta{margin-top:14px;padding:10px;border:1px solid #e4003f;color:#e4003f;border-radius:8px}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir</button>
<div class="hoja">
  <table style="margin:0"><tr><td style="border:0;padding:0"><h1>Hoja de preparación</h1><div class="mut">{{ $b->name }} · {{ $r['deposito'] }}</div></td>
    <td class="r" style="border:0;padding:0"><b>{{ $r['comprobante']['nombre'] }}</b><br>{{ $r['comprobante']['cliente'] ?? 'Consumidor final' }}<br><span class="mut">{{ $r['comprobante']['fecha'] }}</span></td></tr></table>
  <table>
    <tr><th style="width:26px"></th><th>Ubicación</th><th>Artículo</th><th>Lote</th><th class="r">Cantidad</th></tr>
    @forelse($r['pasos'] as $p)
    <tr><td><div class="check"></div></td><td class="ubic">{{ $p['codigo'] }}</td><td><b>{{ $p['nombre'] }}</b><br><span class="mut">{{ $p['sku'] }} {{ $p['barcode'] ? '· ' . $p['barcode'] : '' }}</span></td><td>{{ $p['lote'] ?? '—' }}</td><td class="r" style="font-size:15px"><b>{{ $n($p['cantidad']) }}</b> {{ $p['unidad'] }}</td></tr>
    @empty
    <tr><td colspan="5" class="mut">Nada ubicado para este comprobante.</td></tr>
    @endforelse
  </table>
  @if($r['faltan'])
  <div class="falta"><b>Sin ubicación asignada:</b>
    @foreach($r['faltan'] as $f)<br>{{ $f['nombre'] }}: {{ $n($f['cantidad']) }} ({{ $n($f['sin_ubicar']) }} sin ubicar en el depósito)@endforeach
  </div>
  @endif
  <p class="mut" style="margin-top:24px">Preparó: ______________________ &nbsp;&nbsp; Controló: ______________________</p>
</div>
</body>
</html>
