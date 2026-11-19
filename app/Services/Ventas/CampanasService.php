<?php

namespace App\Services\Ventas;

use App\Jobs\EnviarCampanaJob;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Campana;
use App\Models\Contact;
use App\Models\User;
use App\Services\Envios\EnvioService;
use Illuminate\Support\Facades\Auth;

// Fase 96: un mensaje a muchos clientes de una vez. Mail o WhatsApp (por API si está, si no quedan los links listos para mandar
// a mano), con variables ({cliente}, {saldo}, {empresa}, {portal}). Sale en cola para no trabar la pantalla.
class CampanasService
{
    public const MAXIMO = 500;

    // A quién: los mismos filtros de la pantalla de Clientes.
    public function destinatarios(Business $b, array $f, string $canal)
    {
        return Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('type', ['customer', 'both'])
            ->where('is_active', ($f['estado'] ?? 'activos') !== 'inactivos')
            ->when(! empty($f['tipo_cliente_id']), fn($q) => $q->where('tipo_cliente_id', $f['tipo_cliente_id']))
            ->when(($f['estado'] ?? null) === 'deudores', fn($q) => $q->where('balance', '>', 0.005))
            ->when(! empty($f['buscar']), fn($q, $x) => $q->where(fn($w) => $w->where('name', 'like', "%{$f['buscar']}%")->orWhere('cuit', 'like', "%{$f['buscar']}%")->orWhere('email', 'like', "%{$f['buscar']}%")))
            ->when(! empty($f['ids']), fn($q) => $q->whereIn('id', (array) $f['ids']))
            ->where(fn($q) => $canal === 'mail' ? $q->whereNotNull('email')->where('email', '!=', '') : $q->where(fn($w) => $w->whereNotNull('mobile')->where('mobile', '!=', '')->orWhere(fn($z) => $z->whereNotNull('phone')->where('phone', '!=', ''))))
            ->orderBy('name')->limit(self::MAXIMO)->get();
    }

    public function contar(Business $b, array $f): array
    {
        return ['mail' => $this->destinatarios($b, $f, 'mail')->count(), 'whatsapp' => $this->destinatarios($b, $f, 'whatsapp')->count()];
    }

    public function texto(Business $b, Contact $c, string $texto): string
    {
        return strtr($texto, ['{cliente}' => $c->name, '{saldo}' => '$ ' . number_format((float) $c->balance, 2, ',', '.'), '{empresa}' => $b->name, '{portal}' => $c->portal_token ? url('/portal/' . $c->portal_token) : '']);
    }

    public function crear(User $u, string $canal, string $texto, ?string $asunto, array $filtro): Campana
    {
        $b = $u->business;
        $dest = $this->destinatarios($b, $filtro, $canal);
        abort_if($dest->isEmpty(), 422, 'Ningún cliente del filtro tiene ' . ($canal === 'mail' ? 'email' : 'teléfono') . '.');
        $c = Campana::create(['business_id' => $b->id, 'user_id' => $u->id, 'canal' => $canal, 'asunto' => $asunto, 'texto' => $texto, 'filtro' => $filtro, 'destinatarios' => $dest->count()]);
        AuditLog::registrar('crear', $c, "Mensaje por {$canal} a {$dest->count()} cliente(s): " . mb_substr($asunto ?: $texto, 0, 80));
        foreach ($dest as $d) EnviarCampanaJob::dispatch($c->id, $d->id, $u->id);
        return $c;
    }

    // Un envío de la campaña (lo corre el job, con el usuario que la mandó).
    public function enviarA(Campana $camp, Contact $c, EnvioService $envios): void
    {
        $b = $camp->business;
        $texto = $this->texto($b, $c, $camp->texto);
        $destino = $camp->canal === 'mail' ? $c->email : ($c->mobile ?: $c->phone);
        $e = $envios->enviar($c, $camp->canal, $destino, 'campana', $texto, false, $camp->asunto ?: null);
        $e->update(['campana_id' => $camp->id]);
        $camp->recontar();
        if ($camp->enviados + $camp->pendientes + $camp->errores >= $camp->destinatarios) $camp->update(['terminada_en' => now()]);
    }

    public function listado(Business $b): array
    {
        return Campana::withoutGlobalScopes()->where('business_id', $b->id)->with('user:id,name')->latest()->limit(30)->get()->map(fn($c) => [
            'id' => $c->id, 'fecha' => $c->created_at->format('d/m/Y H:i'), 'canal' => Campana::CANALES[$c->canal] ?? $c->canal, 'canal_key' => $c->canal, 'asunto' => $c->asunto, 'texto' => $c->texto, 'usuario' => $c->user?->name,
            'destinatarios' => $c->destinatarios, 'enviados' => $c->enviados, 'pendientes' => $c->pendientes, 'errores' => $c->errores, 'terminada' => (bool) $c->terminada_en,
            'para_mandar' => $c->canal === 'whatsapp' ? $c->envios()->where('estado', 'pendiente')->orderBy('id')->with('contact:id,name')->get()->map(fn($e) => ['id' => $e->id, 'cliente' => $e->contact?->name, 'link' => $e->link])->values()->all() : [],
            'fallidos' => $c->envios()->where('estado', 'error')->with('contact:id,name')->limit(20)->get()->map(fn($e) => ['cliente' => $e->contact?->name, 'error' => $e->error])->values()->all(),
        ])->all();
    }
}
