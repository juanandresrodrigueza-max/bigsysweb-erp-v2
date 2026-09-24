<?php

namespace App\Jobs;

use App\Models\Catalogo;
use App\Models\Contact;
use App\Models\User;
use App\Services\Envios\EnvioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;

// Un catálogo a un cliente, en cola (envío masivo por lista).
class EnviarCatalogoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $catalogoId, public int $contactId, public string $canal, public int $userId) {}

    public function handle(EnvioService $envios): void
    {
        $user = User::find($this->userId); if (! $user) return;
        Auth::setUser($user);
        $cat = Catalogo::find($this->catalogoId); $cli = Contact::find($this->contactId);
        if (! $cat || ! $cli || ! $cat->activo) return;
        $cat->contact = $cli;
        $envios->enviar($cat, $this->canal, $this->canal === 'mail' ? $cli->email : ($cli->mobile ?: $cli->phone), 'catalogo');
    }
}
