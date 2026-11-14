<?php

namespace App\Jobs;

use App\Models\Campana;
use App\Models\Contact;
use App\Models\User;
use App\Services\Envios\EnvioService;
use App\Services\Ventas\CampanasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;

// Fase 96: un mensaje de una campaña a un cliente, en cola.
class EnviarCampanaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $backoff = 60;

    public function __construct(public int $campanaId, public int $contactId, public int $userId) {}

    public function handle(CampanasService $svc, EnvioService $envios): void
    {
        $user = User::find($this->userId); if (! $user) return;
        Auth::setUser($user);
        $camp = Campana::withoutGlobalScopes()->find($this->campanaId); $c = Contact::withoutGlobalScopes()->find($this->contactId);
        if (! $camp || ! $c) return;
        $svc->enviarA($camp, $c, $envios);
    }
}
