<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Contact;
use App\Models\SistemaConfig;
use App\Services\Canales\ConexionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Fase 125: los tres avisos de datos personales que Tienda Nube les exige a las aplicaciones (LGPD).
//
// Se cargan una sola vez en el portal de socios, en la aplicación de BigSys:
//   store/redact            → la tienda desinstaló la aplicación hace 48 h: se borran sus claves.
//   customers/redact        → un comprador pidió que se borren sus datos.
//   customers/data_request  → un comprador pidió una copia de sus datos.
// Tienda Nube firma cada aviso con el secreto de la aplicación (x-linkedstore-hmac-sha256): si la firma no da, se rechaza.
//
// En el ERP el responsable de los datos de sus clientes es cada empresa, y lo que está en una factura se conserva por
// obligación fiscal (10 años). Por eso los pedidos de un comprador no borran nada solos: le dejan a la empresa un aviso con
// lo que pidió y a quién corresponde, para que lo resuelva desde la ficha del cliente. Lo de la tienda (sus claves) sí se borra.
class TiendanubeLgpdController extends Controller
{
    public const TEMAS = ['store-redact', 'customers-redact', 'customers-data-request'];

    public function recibir(Request $r, string $tema): JsonResponse
    {
        if (! $this->firmaValida($r)) return response()->json(['ok' => false, 'error' => 'firma inválida'], 401);
        $d = $r->json()->all();
        $tienda = (string) ($d['store_id'] ?? '');
        $canal = $tienda !== '' ? Canal::withoutGlobalScopes()->where('tipo', 'tiendanube')->where('external_user_id', $tienda)->first() : null;
        Log::info("Tienda Nube LGPD {$tema}", ['store_id' => $tienda, 'canal' => $canal?->id]);
        if (! $canal) return response()->json(['ok' => true, 'tienda' => 'desconocida']); // nada guardado de esa tienda

        match ($tema) {
            'store-redact' => $this->borrarTienda($canal),
            'customers-redact' => $this->avisar($canal, $d, 'borrar'),
            'customers-data-request' => $this->avisar($canal, $d, 'copia'),
        };
        return response()->json(['ok' => true]);
    }

    private function firmaValida(Request $r): bool
    {
        $s = (string) SistemaConfig::get('tn_app_secret');
        $h = (string) $r->header('X-Linkedstore-Hmac-Sha256');
        return $s !== '' && $h !== '' && hash_equals(hash_hmac('sha256', $r->getContent(), $s), strtolower($h));
    }

    private function borrarTienda(Canal $c): void
    {
        if ($c->credenciales) app(ConexionesService::class)->desconectar($c);
        $c->forceFill(['external_user_id' => null])->save();
        Alerta::withoutGlobalScopes()->create(['business_id' => $c->business_id, 'modulo' => 'configuracion', 'tipo' => 'tiendanube_lgpd', 'severidad' => 'info',
            'modelo' => 'Canal', 'modelo_id' => $c->id, 'url' => '/configuracion/tienda',
            'titulo' => 'Tienda Nube: se borraron las claves de tu tienda',
            'detalle' => 'Desinstalaste la aplicación de BigSys en Tienda Nube y, como pide Tienda Nube, se borró el acceso a tu tienda. Los pedidos y las ventas que ya están en el ERP quedan. Para volver a conectarla: Configuración → Tienda y canales.']);
    }

    private function avisar(Canal $c, array $d, string $que): void
    {
        $cl = (array) ($d['customer'] ?? []);
        $email = trim((string) ($cl['email'] ?? '')); $doc = preg_replace('/\D/', '', (string) ($cl['identification'] ?? ''));
        $quien = trim(($cl['name'] ?? '') . ($email ? " ({$email})" : '')) ?: 'un comprador';
        $contacto = Contact::withoutGlobalScopes()->where('business_id', $c->business_id)
            ->where(fn($q) => $q->when($email, fn($w) => $w->orWhere('email', $email))->when($doc, fn($w) => $w->orWhere('document', $doc)->orWhere('cuit', $doc)))
            ->when(! $email && ! $doc, fn($q) => $q->whereRaw('1 = 0'))->first();
        $pedidos = collect($d[$que === 'borrar' ? 'orders_to_redact' : 'orders_requested'] ?? [])->map(fn($o) => is_array($o) ? ($o['id'] ?? null) : $o)->filter()->implode(', ');
        $donde = $contacto ? "Es el cliente «{$contacto->name}» del ERP." : 'No hay un cliente del ERP con ese mail o documento.';
        $titulo = $que === 'borrar' ? "Tienda Nube: {$quien} pidió que borres sus datos" : "Tienda Nube: {$quien} pidió una copia de sus datos";
        $detalle = $que === 'borrar'
            ? "{$donde} Borrá o cambiá lo que no haga falta (teléfono, dirección, notas). Lo que está en facturas se guarda 10 años por obligación fiscal: eso no se borra."
            : "{$donde} Mandale lo que tenés de esa persona (datos de contacto y sus compras: la ficha del cliente tiene el detalle) dentro de los 10 días.";
        if ($pedidos) $detalle .= " Pedidos de Tienda Nube: {$pedidos}.";
        if (! empty($d['data_request']['id'])) $detalle .= " Pedido n.º {$d['data_request']['id']}.";
        Alerta::withoutGlobalScopes()->create(['business_id' => $c->business_id, 'modulo' => 'clientes', 'tipo' => 'tiendanube_lgpd', 'severidad' => 'aviso',
            'modelo' => $contacto ? 'Contact' : 'Canal', 'modelo_id' => $contacto?->id ?? $c->id, 'url' => $contacto ? "/clientes/{$contacto->id}" : '/clientes',
            'titulo' => mb_substr($titulo, 0, 150), 'detalle' => mb_substr($detalle, 0, 600)]);
        AuditLog::registrar('editar', $c, $titulo);
    }
}
