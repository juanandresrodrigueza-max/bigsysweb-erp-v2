<?php

namespace App\Services\Canales;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\NotaCliente;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Support\Sql;
use Illuminate\Support\Str;

// Fase 60 · Bigcito atiende a los clientes por WhatsApp: saldo, estado del pedido, si hay un artículo y a cuánto, y los reclamos
// quedan anotados en la ficha del cliente con aviso al dueño. Lo que no es una consulta sigue el camino de siempre (pedido).
class AtencionClientesService
{
    public const INTENCIONES = ['saldo', 'pedido', 'stock', 'reclamo', 'persona'];

    public static function activo(Business $b): bool { return (bool) ($b->whatsapp_settings['atencion_clientes'] ?? true); }

    private static function norm(string $t): string { return ' ' . preg_replace('/\s+/', ' ', Str::lower(Str::ascii($t))) . ' '; }

    // El cliente detrás del número (últimos 8 dígitos del teléfono o celular) o del CUIT/DNI que escribió en el mensaje.
    public function cliente(Business $b, ?string $tel, string $texto = ''): ?Contact
    {
        $q = Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('type', 'customer');
        if ($tel && strlen($d = preg_replace('/\D/', '', $tel)) >= 8) {
            $fin = '%' . substr($d, -8);
            $c = (clone $q)->where(fn($w) => $w->whereRaw("replace(replace(replace(coalesce(phone,''),'-',''),' ',''),'+','') like ?", [$fin])->orWhereRaw("replace(replace(replace(coalesce(mobile,''),'-',''),' ',''),'+','') like ?", [$fin]))->first();
            if ($c) return $c;
        }
        if (preg_match('/\b(\d{2}-?\d{8}-?\d|\d{7,8})\b/', $texto, $m)) { $doc = preg_replace('/\D/', '', $m[1]); $c = (clone $q)->where(fn($w) => $w->whereRaw("replace(coalesce(cuit,''),'-','') = ?", [$doc])->orWhere('document', $doc))->first(); if ($c) return $c; }
        return null;
    }

    // Qué quiere, por palabras. null = no se nota a simple vista (sigue a la interpretación con IA, que también clasifica).
    public function intencion(Business $b, string $texto): ?array
    {
        $t = self::norm($texto);
        $reglas = [
            'reclamo' => '/\b(reclamo|queja|problema|roto|rota|golpead|fallad|vino mal|llego mal|llegaron mal|falto|faltaron|falta un|faltan|equivocad|devolver|devolucion|no funciona|no anda|vencid|me cobraron de mas|mal cobrad)/',
            'saldo' => '/\b(saldo|cuanto (te |les )?debo|debo algo|deuda|cuenta corriente|estado de cuenta|resumen de cuenta|factura(s)? pendiente|cuanto tengo que pagar|que tengo pendiente)/',
            'pedido' => '/\b(mi pedido|el pedido|donde esta|donde anda|cuando llega|cuando me llega|cuando sale|estado del pedido|ya salio|seguimiento|lo despacharon|cuando lo mandan|cuando me lo traen)/',
            'persona' => '/\b(hablar con|una persona|con alguien|un humano|atencion al cliente|que me llamen|llamame|telefono de ustedes)/',
            'stock' => '/\b(tenes|tienen|tendran|hay|stock|disponible|disponibilidad|precio|cuanto sale|cuanto esta|cuanto cuesta|cuanto vale|a cuanto|les queda|consigo|venden)\b/',
        ];
        foreach ($reglas as $int => $re) if (preg_match($re, $t)) return ['intencion' => $int, 'articulo' => $int === 'stock' ? $this->articuloEn($texto) : null, 'detalle' => trim($texto)];
        return null; // lo dudoso lo decide la IA dentro de la interpretación del pedido (una sola llamada): ver atenderInterpretado()
    }

    private function articuloEn(string $texto): ?string
    {
        $t = trim(preg_replace('/[¿?¡!.,]+/u', ' ', Str::lower($texto)));
        $t = preg_replace('/\b(hola|buenas|buen dia|buenos dias|buenas tardes|che|tenes|tienen|tendran|hay|stock|de|disponible|disponibilidad|precio|cuanto|sale|esta|cuesta|vale|a|les|queda|consigo|venden|el|la|los|las|un|una|unos|unas|por|favor|me|decis|si|gracias|quiero|saber|necesito)\b/u', ' ', Str::ascii($t));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        return $t !== '' ? $t : null;
    }

    // La respuesta para el cliente, o null si no era una consulta a simple vista.
    public function atender(Business $b, ?string $tel, string $texto): ?array
    {
        $i = $this->intencion($b, $texto); return $i ? $this->responderA($b, $tel, $texto, $i) : null;
    }

    // Después de interpretar el mensaje con la IA del tomador de pedidos: si no pidió nada y la IA lo marcó como consulta, se atiende.
    public function atenderInterpretado(Business $b, ?string $tel, string $texto, array $interp): ?array
    {
        if (! empty($interp['items']) || ! in_array($interp['consulta'] ?? null, self::INTENCIONES, true)) return null;
        return $this->responderA($b, $tel, $texto, ['intencion' => $interp['consulta'], 'articulo' => $interp['articulo_consulta'] ?? $this->articuloEn($texto), 'detalle' => trim($texto)]);
    }

    private function responderA(Business $b, ?string $tel, string $texto, array $i): array
    {
        $c = $this->cliente($b, $tel, $texto);
        $resp = match ($i['intencion']) {
            'saldo' => $this->saldo($b, $c),
            'pedido' => $this->pedido($b, $c, $tel),
            'stock' => $this->stock($b, $i['articulo'] ?? null),
            'reclamo' => $this->reclamo($b, $c, $tel, $texto),
            'persona' => $this->persona($b, $c, $tel, $texto),
        };
        return ['intencion' => $i['intencion'], 'respuesta' => $resp, 'cliente_id' => $c?->id];
    }

    private function saludo(?Contact $c): string { return 'Hola' . ($c ? ' ' . Str::of($c->name)->explode(' ')->first() : '') . '. '; }

    private function saldo(Business $b, ?Contact $c): string
    {
        if (! $c) return 'Hola. No encuentro este número entre nuestros clientes. Pasame tu CUIT o DNI y te digo el saldo al instante.';
        $saldo = (float) $c->balance;
        if ($saldo <= 0.005) return $this->saludo($c) . 'No tenés saldo pendiente con nosotros. ¡Gracias!';
        $pend = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $c->id)->ventas()->pendientesCobro()->orderBy('fecha')->limit(4)->get();
        $venc = (float) $pend->filter(fn($f) => $f->fecha_vto && $f->fecha_vto->lt(today()))->sum('saldo');
        $lineas = $pend->map(fn($f) => "• {$f->numeroFormateado()} del {$f->fecha->format('d/m')}: " . $this->pesos((float) $f->saldo) . ($f->fecha_vto && $f->fecha_vto->lt(today()) ? ' (vencida)' : ''))->implode("\n");
        $pago = ($b->tienda['cbu'] ?? $b->cbu_fce ?? null) ? "\nPodés transferir al CBU/alias " . ($b->tienda['alias'] ?? $b->tienda['cbu'] ?? $b->cbu_fce) . ' y mandarnos el comprobante por acá.' : '';
        return $this->saludo($c) . 'Tu saldo es ' . $this->pesos($saldo) . ($venc > 0 ? ' (vencido: ' . $this->pesos($venc) . ')' : '') . ".\n" . $lineas . $pago;
    }

    private function pedido(Business $b, ?Contact $c, ?string $tel): string
    {
        $q = PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->latest('id');
        $fin = $tel ? substr(preg_replace('/\D/', '', $tel), -8) : null;
        $p = $q->where(fn($w) => $w->when($c, fn($x) => $x->where('contact_id', $c->id))->when($fin, fn($x) => $x->orWhere('cliente', 'like', "%{$fin}%")))->first();
        if (! $p) return $this->saludo($c) . 'No veo pedidos recientes tuyos. Si querés hacer uno, escribime qué necesitás y lo cargo.';
        $estado = PedidoWeb::ESTADOS[$p->estado] ?? $p->estado;
        $txt = $this->saludo($c) . "Tu pedido {$p->numeroFormateado()} del {$p->created_at->format('d/m')} está: {$estado}.";
        if ($p->estado === 'enviado') $txt .= ' Ya salió para tu dirección.'; elseif ($p->estado === 'entregado') $txt .= ' ¡Gracias por tu compra!'; elseif ($p->estado === 'cancelado') $txt .= ' Si no fuiste vos quien lo canceló, avisanos.'; else $txt .= ' Te avisamos cuando salga.';
        return $txt . "\nSeguilo acá: {$p->urlPublica()}";
    }

    private function stock(Business $b, ?string $que): string
    {
        if (! $que) return 'Hola. ¿Qué artículo estás buscando? Decime el nombre y te digo si lo tenemos y el precio.';
        $like = Sql::like(); $palabras = array_values(array_filter(preg_split('/\s+/', Str::ascii($que)), fn($w) => mb_strlen($w) >= 2));
        $base = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio']);
        $buscar = fn($q) => $q->where(function ($w) use ($palabras, $like) { foreach ($palabras as $p) $w->where('name', $like, "%{$p}%"); })->orderByDesc('en_tienda')->limit(3)->get();
        $prods = $buscar(clone $base);
        if ($prods->isEmpty() && count($palabras) > 1) $prods = (clone $base)->where(function ($w) use ($palabras, $like) { foreach ($palabras as $p) $w->orWhere('name', $like, "%{$p}%"); })->orderByDesc('en_tienda')->limit(3)->get();
        if ($prods->isEmpty()) return "Hola. No encontré «{$que}» en nuestra lista. Contame un poco más (marca, medida) o decinos qué buscás y te respondemos enseguida.";
        $lineas = $prods->map(function (Product $p) {
            $hay = ! $p->controla_stock || (float) $p->stock > 0; $poco = $p->controla_stock && $hay && (float) $p->stock <= (float) ($p->stock_min ?? 0);
            return "• {$p->name}: " . ($hay ? $this->pesos((float) $p->price) . ($p->unit ? " por {$p->unit}" : '') . ($poco ? ' (quedan pocas unidades)' : ' (hay stock)') : 'sin stock por ahora, ¿te avisamos cuando entre?');
        })->implode("\n");
        return "Hola. Esto es lo que tenemos:\n{$lineas}\nSi querés, decime cuántos y te armo el pedido.";
    }

    private function reclamo(Business $b, ?Contact $c, ?string $tel, string $texto): string
    {
        $nota = $c ? NotaCliente::create(['business_id' => $b->id, 'contact_id' => $c->id, 'user_id' => null, 'texto' => '📣 Reclamo por WhatsApp: ' . mb_substr($texto, 0, 500), 'created_at' => now()]) : null;
        $tarea = null; try { $tarea = \App\Services\Integraciones\CrmSyncService::tarea($b, $c, 'Reclamo por WhatsApp de ' . ($c?->name ?? $tel ?? 'un cliente'), mb_substr($texto, 0, 1000) . ($tel ? "\nTeléfono: {$tel}" : ''), 'call', 'high'); } catch (\Throwable) {} // Fase 71: si hay CRM, el equipo lo ve como tarea
        Alerta::emitir(['business_id' => $b->id, 'modulo' => 'clientes', 'tipo' => 'reclamo_whatsapp', 'severidad' => 'warning', 'modelo' => 'whatsapp', 'modelo_id' => $nota?->id ?? crc32(($tel ?? '') . now()->timestamp),
            'titulo' => 'Reclamo por WhatsApp de ' . ($c?->name ?? $tel ?? 'un cliente'), 'detalle' => mb_substr($texto, 0, 260) . ($tarea ? " · tarea del CRM #{$tarea}" : ''), 'url' => $c ? "/clientes/{$c->id}" : '/comprobantes/pedidos']);
        return $this->saludo($c) . 'Lamento lo que pasó. Tomé nota del reclamo' . ($nota ? " (#{$nota->id})" : '') . ' y ya le avisé al equipo: una persona te escribe a la brevedad para resolverlo.';
    }

    private function persona(Business $b, ?Contact $c, ?string $tel, string $texto): string
    {
        $tarea = null; try { $tarea = \App\Services\Integraciones\CrmSyncService::tarea($b, $c, ($c?->name ?? $tel ?? 'Un cliente') . ' quiere hablar con alguien (WhatsApp)', mb_substr($texto, 0, 1000) . ($tel ? "\nTeléfono: {$tel}" : ''), 'call', 'normal'); } catch (\Throwable) {} // Fase 71
        Alerta::emitir(['business_id' => $b->id, 'modulo' => 'clientes', 'tipo' => 'whatsapp_persona', 'severidad' => 'info', 'modelo' => 'whatsapp', 'modelo_id' => crc32(($tel ?? '') . now()->timestamp),
            'titulo' => ($c?->name ?? $tel ?? 'Un cliente') . ' quiere hablar con alguien por WhatsApp', 'detalle' => mb_substr($texto, 0, 260) . ($tarea ? " · tarea del CRM #{$tarea}" : ''), 'url' => $c ? "/clientes/{$c->id}" : '/comprobantes/pedidos']);
        return $this->saludo($c) . 'Ya le avisé a una persona del equipo; te escriben por acá enseguida.';
    }

    private function pesos(float $n): string { return '$ ' . number_format($n, 2, ',', '.'); }
}
