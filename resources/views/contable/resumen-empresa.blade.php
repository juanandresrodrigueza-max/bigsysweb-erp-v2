<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Resumen {{ $periodo }}</title>
<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#1c1a18;margin:28px}h1{font-size:18px;margin:0}table{width:100%;border-collapse:collapse;margin-top:16px}td{padding:8px;border-bottom:1px solid #e6e2dc}td.n{text-align:right}.muted{color:#6f6a62;font-size:10px}.tot{font-weight:bold;font-size:14px}</style></head>
<body>
<table style="margin-top:0"><tr><td style="border:0;padding:0">@if($logo)<img src="{{ $logo }}" style="max-height:60px;max-width:180px">@else<strong style="font-size:16px">{{ $marca }}</strong>@endif</td><td class="n" style="border:0;padding:0"><span class="muted">{{ $marca }}@if($estudio->email) · {{ $estudio->email }}@endif</span></td></tr></table>
<h1 style="margin-top:20px">Resumen de {{ $b->name }}</h1>
<p class="muted">{{ $b->cuit ? 'CUIT ' . $b->cuit . ' · ' : '' }}{{ $b->condicion_iva }} · {{ ucfirst($periodo) }}</p>
@if($mensaje)<p style="white-space:pre-line">{{ $mensaje }}</p>@endif
@php($m = fn($v) => '$ ' . number_format($v, 2, ',', '.'))
<table>
<tr><td>Ventas ({{ $r['ventas_n'] }} comprobantes)</td><td class="n">{{ $m($r['ventas']) }}</td></tr>
<tr><td>Compras ({{ $r['compras_n'] }} comprobantes)</td><td class="n">{{ $m($r['compras']) }}</td></tr>
<tr><td>IVA débito fiscal</td><td class="n">{{ $m($r['iva_debito']) }}</td></tr>
<tr><td>IVA crédito fiscal</td><td class="n">{{ $m($r['iva_credito']) }}</td></tr>
<tr class="tot"><td>{{ $r['iva_saldo'] > 0 ? 'IVA a pagar' : 'Saldo técnico a favor' }}</td><td class="n">{{ $m(abs($r['iva_saldo'])) }}</td></tr>
</table>
<p class="muted" style="margin-top:24px">Preparado por {{ $marca }} con BigSysWeb ERP. Importes con IVA según los comprobantes cargados del período.</p>
</body></html>
