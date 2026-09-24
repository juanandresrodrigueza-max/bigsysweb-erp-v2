<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Libro diario {{ $desde }} a {{ $hasta }}</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #222; margin: 24px; }
  h1 { font-size: 16px; margin: 0; color: #4f3089; }
  .cab { display: flex; justify-content: space-between; border-bottom: 2px solid #4f3089; padding-bottom: 6px; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; background: #f3f0f8; padding: 4px 6px; border-bottom: 1px solid #bbb; font-size: 10px; text-transform: uppercase; }
  td { padding: 3px 6px; border-bottom: 1px solid #eee; vertical-align: top; }
  .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .as { background: #fafafa; font-weight: bold; }
  .haber { padding-left: 24px; }
  tfoot td { font-weight: bold; border-top: 2px solid #4f3089; }
  @media print { .np { display: none; } }
</style>
</head>
<body>
  <div class="cab">
    <div><h1>LIBRO DIARIO</h1><div>{{ $empresa->name }} · CUIT {{ $empresa->cuit }}</div></div>
    <div style="text-align:right"><div>Período {{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }}</div><div>{{ $asientos->count() }} asientos · impreso {{ now()->format('d/m/Y H:i') }}</div></div>
  </div>
  <button class="np" onclick="window.print()" style="margin-bottom:10px">Imprimir</button>
  <table>
    <thead><tr><th style="width:60px">N°</th><th style="width:70px">Fecha</th><th>Cuenta / concepto</th><th class="num" style="width:110px">Debe</th><th class="num" style="width:110px">Haber</th></tr></thead>
    <tbody>
      @php $td = 0; $th = 0; @endphp
      @foreach ($asientos as $a)
        <tr class="as"><td>{{ $a->numero }}</td><td>{{ $a->fecha->format('d/m/Y') }}</td><td>{{ $a->concepto }}</td><td></td><td></td></tr>
        @foreach ($a->lineas->sortByDesc('debe') as $l)
          @php $td += (float) $l->debe; $th += (float) $l->haber; @endphp
          <tr><td></td><td></td><td class="{{ (float) $l->haber > 0 ? 'haber' : '' }}">{{ $l->cuenta?->codigo }} {{ $l->cuenta?->nombre }} @if ($l->detalle) <span style="color:#777"> · {{ $l->detalle }}</span> @endif </td><td class="num">{{ (float) $l->debe > 0 ? number_format((float) $l->debe, 2, ',', '.') : '' }}</td><td class="num">{{ (float) $l->haber > 0 ? number_format((float) $l->haber, 2, ',', '.') : '' }}</td></tr>
        @endforeach
      @endforeach
      @if ($asientos->isEmpty())
        <tr><td colspan="5" style="text-align:center;color:#888;padding:20px">Sin asientos en el período.</td></tr>
      @endif
    </tbody>
    <tfoot><tr><td colspan="3">Totales</td><td class="num">{{ number_format($td, 2, ',', '.') }}</td><td class="num">{{ number_format($th, 2, ',', '.') }}</td></tr></tfoot>
  </table>
</body>
</html>
