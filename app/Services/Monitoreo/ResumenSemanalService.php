<?php

namespace App\Services\Monitoreo;

use App\Models\Backup;
use App\Models\Business;
use App\Models\ErrorSistema;
use App\Models\SistemaConfig;
use App\Models\Ticket;
use App\Services\Producto\UsoService;
use Illuminate\Support\Facades\Mail;

// Fase 76: el resumen semanal del sistema para el dueño de BigSys, con uso real.
class ResumenSemanalService
{
    public function __construct(private UsoService $uso, private SaludService $salud) {}

    public function armar(): array
    {
        $desde = now()->subDays(7); $g = $this->uso->global(7);
        $err = ErrorSistema::where('ultima_vez', '>=', $desde)->orderByDesc('veces')->get();
        $s = $this->salud->verificar();
        $sinCopia = Business::where('is_active', true)->where('backup_auto', true)->get()->filter(fn($b) => ! Backup::withoutGlobalScopes()->where('business_id', $b->id)->where('created_at', '>=', now()->subHours(48))->exists())->pluck('name')->values()->all();
        return [
            'desde' => $desde->format('d/m'), 'hasta' => now()->format('d/m'),
            'uso' => ['empresas' => $g['kpis']['empresas'], 'activas' => $g['kpis']['activas_7'], 'usuarios_activos' => $g['kpis']['usuarios_activos'] ?? 0, 'vistas' => collect($g['ranking'])->sum('vistas')],
            'ranking' => array_slice($g['ranking'], 0, 5), 'en_riesgo' => array_slice($g['en_riesgo'], 0, 8), 'modulos' => array_slice(array_values((array) $g['modulos']), 0, 6),
            'errores' => ['total' => (int) $err->sum('veces'), 'distintos' => $err->count(), 'sin_resolver' => $err->whereNull('resuelto_en')->count(),
                'principales' => $err->take(5)->map(fn($e) => ['codigo' => substr($e->hash, 0, 8), 'que' => class_basename($e->clase) . ': ' . \Illuminate\Support\Str::limit($e->mensaje, 100), 'donde' => "{$e->archivo}:{$e->linea}", 'veces' => $e->veces])->values()->all()],
            'salud' => ['estado' => $s['estado'], 'con_problemas' => array_values(array_filter($s['controles'], fn($c) => $c['estado'] !== 'ok'))],
            'copias' => ['hechas' => Backup::withoutGlobalScopes()->where('created_at', '>=', $desde)->count(), 'sin_copia_48h' => $sinCopia],
            'soporte' => ['abiertos' => Ticket::withoutGlobalScopes()->where('estado', '!=', 'cerrado')->count(), 'nuevos' => Ticket::withoutGlobalScopes()->where('created_at', '>=', $desde)->count()],
        ];
    }

    public function enviar(): ?string
    {
        $emails = Destinatarios::monitoreo(); if (! $emails) return null;
        Mail::to($emails)->send(new \App\Mail\ResumenSemanalSistema($this->armar()));
        return implode(', ', $emails);
    }
}
