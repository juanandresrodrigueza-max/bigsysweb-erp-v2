<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>{{ $b->name }}</title></head>
<body style="margin:0;padding:24px;background:#faf9f7;font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;font-size:14px;line-height:1.5">
  <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e2dc;border-radius:16px;overflow:hidden">
    <div style="background:linear-gradient(135deg,#e4003f,#a42785);color:#fff;padding:18px 22px;font-size:18px;font-weight:800">{{ $b->name }}</div>
    <div style="padding:22px;white-space:pre-line">{{ $cuerpo }}</div>
    <div style="padding:12px 22px;border-top:1px solid #e6e2dc;font-size:11px;color:#6f6a62">{{ $b->razon_social ?? $b->name }}@if($b->cuit) · CUIT {{ $b->cuit }} @endif @if($b->phone) · {{ $b->phone }} @endif @if($b->email) · {{ $b->email }} @endif </div>
  </div>
</body>
</html>
