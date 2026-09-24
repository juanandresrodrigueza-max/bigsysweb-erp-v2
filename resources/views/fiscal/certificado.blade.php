<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Certificado de retención {{ $r->certificado ?: $r->id }}</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; margin: 28px; }
  h1 { font-size: 16px; margin: 0 0 4px; color: #4f3089; }
  .cab { display: flex; justify-content: space-between; border-bottom: 2px solid #4f3089; padding-bottom: 8px; margin-bottom: 14px; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  td, th { padding: 6px 8px; border: 1px solid #ccc; }
  th { background: #f3f0f8; text-align: left; width: 38%; }
  .tot { font-size: 14px; font-weight: bold; }
  .firma { margin-top: 60px; width: 45%; border-top: 1px solid #333; padding-top: 4px; text-align: center; font-size: 10px; }
  .leg { margin-top: 16px; font-size: 9px; color: #666; }
</style>
</head>
<body>
  <div class="cab">
    <div>
      <h1>CERTIFICADO DE RETENCIÓN</h1>
      <div>{{ \App\Models\Retencion::TIPOS[$r->tipo] ?? strtoupper($r->tipo) }} @if ($r->jurisdiccion) · Jurisdicción {{ $r->jurisdiccion }} @endif </div>
    </div>
    <div style="text-align:right">
      <div><b>N° {{ $r->certificado ?: str_pad((string) $r->id, 8, '0', STR_PAD_LEFT) }}</b></div>
      <div>Fecha: {{ $r->fecha->format('d/m/Y') }}</div>
    </div>
  </div>

  <table>
    <tr><th>Agente de retención</th><td><b>{{ $empresa->name }}</b><br>CUIT {{ $empresa->cuit }} @if ($empresa->address) <br>{{ $empresa->address }} @endif </td></tr>
    <tr><th>Sujeto retenido</th><td><b>{{ $r->contact?->name }}</b><br>CUIT {{ $r->contact?->cuit }} @if ($r->contact?->address) <br>{{ $r->contact->address }} @endif </td></tr>
    <tr><th>Impuesto / régimen</th><td>{{ \App\Models\Retencion::TIPOS[$r->tipo] ?? $r->tipo }} @if ($r->tipo === 'ganancias') · RG 830 @elseif ($r->tipo === 'iva') · RG 2854 @elseif ($r->tipo === 'iibb') · Régimen general de retención {{ $r->jurisdiccion ?: '' }} @endif </td></tr>
    <tr><th>Comprobante de pago</th><td>Orden de pago N° {{ $r->pago?->numero }} del {{ $r->pago?->fecha?->format('d/m/Y') }}</td></tr>
    <tr><th>Base imponible</th><td>$ {{ number_format((float) $r->base, 2, ',', '.') }}</td></tr>
    <tr><th>Alícuota</th><td>{{ number_format((float) $r->alicuota, 2, ',', '.') }} %</td></tr>
    <tr><th>Importe retenido</th><td class="tot">$ {{ number_format((float) $r->monto, 2, ',', '.') }}</td></tr>
  </table>

  <div class="firma">Firma y aclaración del agente de retención</div>
  <p class="leg">Este certificado se emite en cumplimiento de las normas vigentes y acredita la retención practicada al sujeto indicado, quien podrá computarla contra el impuesto correspondiente. Emitido por BigSysWeb.</p>
</body>
</html>
