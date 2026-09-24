{{-- Estilos comunes de los comprobantes impresos. Sin grid, flex ni variables CSS: dompdf no los entiende. --}}
@php $m ??= $b->marcaImpresion(); @endphp
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,DejaVu Sans,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:11.5px}
  .hoja{max-width:800px;margin:0 auto;border:1px solid #d6d1ca;border-radius:10px;overflow:hidden;position:relative}
  .marca-agua{position:absolute;top:40%;left:0;right:0;text-align:center;font-size:80px;font-weight:800;color:rgba(0,0,0,.06);transform:rotate(-20deg)}
  table{width:100%;border-collapse:collapse}
  .cab td{vertical-align:top;padding:14px 16px}
  .cab .letra{width:86px;text-align:center;vertical-align:middle;border-left:1px solid #d6d1ca;border-right:1px solid #d6d1ca}
  .letra b{display:block;font-size:34px;line-height:1;color:{{ $m['acento'] }}} .letra small{font-size:8.5px;color:#6f6a62}
  .emp{font-size:17px;font-weight:800;color:{{ $m['acento'] }};margin-bottom:2px}
  .tipo{font-size:14px;font-weight:800;color:{{ $m['titulo'] }}} .num{font-size:13px;font-weight:700;margin:3px 0}
  .fiscal{font-size:10.5px;color:#4a4640;line-height:1.45} .extra{font-size:10px;color:#6f6a62;margin-top:4px;line-height:1.4}
  .logo{max-height:64px;max-width:190px;margin-bottom:6px}
  .banda{background:{{ $m['color_primario'] }};color:{{ $m['texto_primario'] }}}
  .banda .emp,.banda .tipo,.banda .letra b{color:{{ $m['texto_primario'] }}} .banda .fiscal,.banda .extra,.banda .letra small{color:{{ $m['texto_primario'] }};opacity:.9}
  .banda .letra{border-color:rgba(255,255,255,.35)} .banda .letra b{background:#fff;color:{{ $m['color_primario'] }};border-radius:8px;padding:4px 0;margin:0 12px 3px}
  .minimo .letra{border:0} .minimo{border-bottom:2px solid {{ $m['color_primario'] }}}
  .linea{border-bottom:1px solid #d6d1ca}
  .cli td{padding:3px 16px;vertical-align:top} .cli{padding:8px 0}
  .items th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;color:{{ $m['texto_secundario'] }};background:{{ $m['color_secundario'] }};padding:7px 10px}
  .items td{padding:6px 10px;border-bottom:1px solid #eee} .items tr:nth-child(even) td{background:{{ $m['suave'] }}}
  .r{text-align:right} .c{text-align:center}
  .tot{width:270px;margin:10px 16px 10px auto} .tot td{padding:3px 6px} .tot .g td{font-weight:800;font-size:14px;border-top:2px solid {{ $m['color_primario'] }};color:{{ $m['acento'] }}}
  .caja{margin:0 16px 10px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:10.5px}
  .pie td{padding:10px 16px;border-top:1px solid #d6d1ca;font-size:10px;color:#6f6a62;vertical-align:middle}
  .leyenda{padding:8px 16px;font-size:10px;color:#4a4640;border-top:1px solid #eee;text-align:center}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#fde8ee;color:#b0002f;font-weight:700;font-size:9.5px}
  .btn{position:fixed;top:12px;right:12px;background:{{ $m['color_primario'] }};color:{{ $m['texto_primario'] }};border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
