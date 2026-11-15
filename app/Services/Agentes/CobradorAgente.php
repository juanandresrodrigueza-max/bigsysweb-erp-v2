<?php

namespace App\Services\Agentes;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\Propuesta;
use App\Models\User;
use App\Services\Envios\EnvioService;
use App\Services\Ventas\CobranzasService;
use App\Services\Ventas\RecordatorioIaService;
use App\Services\Ventas\RiesgoMoraService;
use App\Support\Ia;

// Fase 107 · El cobrador: cada mañana mira quién debe, elige a quién escribir (vencido, días, riesgo, cuándo fue el último aviso)
// y deja el mensaje redactado para ese cliente. El dueño aprueba y sale por WhatsApp o mail con los envíos de siempre.
class CobradorAgente implements Agente
{
    public const MAX = 15;
    public const DIAS_ENTRE_AVISOS = 5;

    public function __construct(private CobranzasService $cobranzas, private RiesgoMoraService $riesgo, private EnvioService $envios) {}

    public function nombre(): string { return 'cobrador'; }

    public function proponer(Business $b): array
    {
        $deudores = $this->cobranzas->deudores(300)->filter(fn($d) => $d['vencido'] > 0 && ($d['telefono'] || $d['email']));
        if ($deudores->isEmpty()) return [];
        $hace = now()->subDays(self::DIAS_ENTRE_AVISOS);
        $recientes = Envio::whereIn('contact_id', $deudores->pluck('id'))->where('tipo', 'recordatorio')->where('created_at', '>=', $hace)->pluck('contact_id')->flip();
        $contacts = Contact::whereIn('id', $deudores->pluck('id'))->get()->keyBy('id');
        $riesgos = $this->riesgo->evaluar($contacts->values(), $deudores->keyBy('id')->map(fn($d) => ['saldo' => $d['saldo'], 'vencido' => $d['vencido'], 'dias' => $d['dias']])->all());
        $out = [];
        foreach ($deudores->sortByDesc(fn($d) => $d['vencido'] * (1 + min($d['dias'], 90) / 30)) as $d) {
            if (isset($recientes[$d['id']]) || count($out) >= self::MAX) continue;
            $c = $contacts[$d['id']]; $r = $riesgos[$c->id] ?? ['nivel' => 'medio', 'puntaje' => 50, 'motivos' => []];
            $vencidas = Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today())->orderBy('fecha_vto')->get();
            if ($vencidas->isEmpty()) continue;
            $canal = $d['telefono'] ? 'whatsapp' : 'mail'; $destino = $canal === 'whatsapp' ? $d['telefono'] : $d['email'];
            $texto = $this->texto($b, $c, $vencidas, $r, $canal);
            $tono = $r['nivel'] === 'alto' ? 'firme' : ($r['nivel'] === 'bajo' ? 'suave' : 'cordial');
            $out[] = ['clave' => "cobrar-{$c->id}", 'importe' => $d['vencido'], 'prioridad' => $d['dias'] > 30 || $r['nivel'] === 'alto' ? 1 : 2,
                'titulo' => "Escribirle a {$c->name}: " . AgentesService::pesos($d['vencido']) . ' vencidos hace ' . $d['dias'] . ' días',
                'detalle' => "Por {$canal} a {$destino}. Tono {$tono} (riesgo {$r['nivel']}" . ($r['motivos'] ? ': ' . implode('; ', array_slice($r['motivos'], 0, 2)) : '') . ').' . ($vencidas->count() > 1 ? " {$vencidas->count()} facturas vencidas." : ''),
                'datos' => ['cliente_id' => $c->id, 'cliente' => $c->name, 'canal' => $canal, 'destino' => $destino, 'texto' => $texto, 'riesgo' => $r['nivel'], 'facturas' => $vencidas->map(fn($x) => ['id' => $x->id, 'numero' => $x->nombreTipo() . ' ' . $x->numeroFormateado(), 'saldo' => (float) $x->saldo, 'vencio' => $x->fecha_vto->format('d/m')])->values()->all()],
                'accion' => ['tipo' => 'recordar', 'comprobante_id' => $vencidas->first()->id, 'canal' => $canal, 'destino' => $destino]];
        }
        return $out;
    }

    // El mensaje: con IA, escrito para ese cliente (Fase 57); sin IA, la plantilla de Cobranzas con todas las vencidas.
    private function texto(Business $b, Contact $c, $vencidas, array $riesgo, string $canal): string
    {
        $primera = $vencidas->first();
        if (Ia::tiene($b) && ($t = app(RecordatorioIaService::class)->texto($b, $primera, $riesgo, $canal))) return $t;
        $f = fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
        $lista = $vencidas->map(fn($x) => "- {$x->nombreTipo()} {$x->numeroFormateado()}: {$f($x->saldo)} (venció el {$x->fecha_vto->format('d/m')})")->implode("\n");
        $cierre = $riesgo['nivel'] === 'alto' ? 'Necesitamos regularizarlo esta semana; si te sirve, podemos armar un plan de pago.' : 'Si ya lo pagaste, ignorá este mensaje. Cualquier cosa, escribinos.';
        return "Hola {$c->name}, te escribimos de {$b->name} por " . ($vencidas->count() > 1 ? 'estas facturas vencidas' : 'esta factura vencida') . ":\n{$lista}\nTotal: {$f($vencidas->sum('saldo'))}. Podés verla y pagarla acá: {$primera->urlPublica()}\n{$cierre}";
    }

    public function ejecutar(Propuesta $p, User $u): string
    {
        $a = $p->accion; $c = Comprobante::findOrFail($a['comprobante_id']);
        if ((float) $c->saldo <= 0.005) return 'La factura ya estaba cobrada: no se mandó nada.';
        $e = $this->envios->enviar($c, $a['canal'], $a['destino'], 'recordatorio', $p->datos['texto'] ?? null, false);
        return match ($e->estado) { 'error' => "No se pudo enviar: {$e->error}", 'pendiente' => "Mensaje listo para WhatsApp: {$e->link}", default => "Recordatorio enviado por {$a['canal']} a {$a['destino']}." };
    }
}
