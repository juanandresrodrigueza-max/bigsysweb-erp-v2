<?php

namespace App\Console\Commands;

use App\Models\Alerta;
use App\Models\Comprobante;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

// Contingencia: vuelve a pedir el CAE de los comprobantes que quedaron pendientes porque ARCA no respondía.
class AfipReintentar extends Command
{
    protected $signature = 'afip:reintentar {--empresa=}';
    protected $description = 'Reintenta pedir el CAE a ARCA de los comprobantes pendientes';

    public function handle(ComprobanteService $svc): int
    {
        $q = Comprobante::withoutGlobalScopes()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->when($this->option('empresa'), fn($q, $e) => $q->where('business_id', $e))->orderBy('id');
        $ok = 0; $pend = 0;
        foreach ($q->cursor() as $c) {
            $dueno = $c->business?->owner; if (! $dueno) continue;
            Auth::setUser($dueno); // los servicios usan el usuario para empresa y auditoría
            try {
                $r = $svc->reintentarCae($c);
                if ($r['estado'] === 'aprobado') { $ok++; $this->line("{$c->business->name}: {$c->nombreTipo()} {$c->numeroFormateado()} autorizado (CAE {$r['cae']})"); }
                else { $pend++; $this->line("{$c->business->name}: comprobante #{$c->id} sigue {$r['estado']}"); }
            } catch (\Throwable $e) { $pend++; $this->error("#{$c->id}: {$e->getMessage()}"); }
            // Si lleva más de una hora sin CAE, avisar al dueño.
            if ($c->fresh()->afip_estado === 'pendiente' && $c->emitido_en && $c->emitido_en->lt(now()->subHour())) {
                Alerta::emitir(['business_id' => $c->business_id, 'modulo' => 'comprobantes', 'tipo' => 'cae_pendiente', 'modelo' => 'Comprobante', 'modelo_id' => $c->id, 'severidad' => 'aviso', 'titulo' => "Comprobante sin CAE hace más de una hora", 'detalle' => "{$c->nombreTipo()} de {$c->contact?->name} emitido {$c->emitido_en->format('d/m H:i')} sigue sin autorización de ARCA. Se reintenta cada 5 minutos.", 'url' => "/comprobantes/{$c->id}"]);
            }
        }
        Auth::logout();
        $this->info("Autorizados: {$ok} · pendientes: {$pend}");
        return self::SUCCESS;
    }
}
