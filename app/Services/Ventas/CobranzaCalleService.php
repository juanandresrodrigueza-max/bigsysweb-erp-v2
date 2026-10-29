<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\RendicionCalle;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use App\Services\Comprobantes\CobroService;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Fase 29.5: cobrar en la calle. El recibo es real (baja la deuda y el cliente lo firma), pero la plata queda a nombre del
// vendedor en su caja "En mano" hasta que cierra el turno, rinde y el encargado la recibe. Devoluciones con motivo y
// confirmación de entrega con nombre, firma y foto.
class CobranzaCalleService
{
    public const MOTIVOS_DEVOLUCION = ['fallado' => 'Vino fallado', 'vencido' => 'Está vencido', 'no_era' => 'No era lo que pidió', 'no_vendio' => 'No lo pudo vender', 'error_carga' => 'Error al cargar', 'otro' => 'Otro'];

    public function __construct(private CobroService $cobros, private FondosService $fondos, private ComprobanteService $comprobantes, private VenderService $vender) {}

    // La caja "En mano" del vendedor: se crea sola la primera vez que cobra.
    public function cajaDe(User $u): CuentaFondos
    {
        $v = Vendedor::deUsuario($u->id);
        $q = CuentaFondos::where('tipo', 'caja')->where(fn($w) => $v ? $w->where('vendedor_id', $v->id) : $w->where('nombre', "En mano · {$u->name}"));
        return $q->first() ?? CuentaFondos::create(['business_id' => $u->business_id, 'tipo' => 'caja', 'nombre' => 'En mano · ' . ($v?->nombre ?? $u->name), 'moneda' => 'ARS', 'saldo' => 0, 'activa' => true, 'es_default' => false, 'vendedor_id' => $v?->id]);
    }

    public function cobrar(User $u, Contact $c, array $d): Cobro
    {
        return DB::transaction(function () use ($u, $c, $d) {
            $caja = $this->cajaDe($u);
            $medios = collect($d['medios'])->filter(fn($m) => (float) ($m['monto'] ?? 0) > 0)->map(function ($m) use ($caja, $c, $d) {
                if ($m['medio'] === 'cheque') {
                    $dt = $m['datos'] ?? [];
                    if (empty($dt['numero']) || empty($dt['banco'])) throw ValidationException::withMessages(['cheque' => 'El cheque necesita número y banco.']);
                    // El mismo cheque (número y banco) ya cargado: avisa antes de guardarlo otra vez.
                    if (empty($d['forzar_cheque']) && Cheque::where('numero', $dt['numero'])->where('banco', $dt['banco'])->exists()) throw ValidationException::withMessages(['cheque_repetido' => "El cheque {$dt['numero']} de {$dt['banco']} ya está cargado. ¿Es otro?"]);
                    $m['datos'] = ['numero' => $dt['numero'], 'banco' => $dt['banco'], 'fecha_pago' => $dt['fecha_pago'] ?? today()->toDateString(), 'emisor' => $dt['titular'] ?? $c->name, 'echeq' => (bool) ($dt['echeq'] ?? false)];
                    $m['referencia'] = $dt['numero'];
                } elseif (in_array($m['medio'], ['efectivo', 'transferencia'], true)) {
                    $m['cuenta_fondos_id'] = $caja->id; // queda a nombre del vendedor hasta rendir
                } else {
                    throw ValidationException::withMessages(['medios' => 'En la calle se cobra en efectivo, transferencia o cheque.']);
                }
                return $m;
            })->values()->all();
            if (! $medios) throw ValidationException::withMessages(['medios' => 'Cargá al menos un medio con importe.']);
            // Se imputa a las facturas más viejas primero; lo que sobra queda a cuenta.
            $total = round(collect($medios)->sum(fn($m) => (float) $m['monto']), 2);
            $imp = []; $resta = $total;
            foreach (Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->orderBy('id')->get() as $f) {
                if ($resta <= 0.005) break;
                $x = min($resta, (float) $f->saldo); $imp[] = ['comprobante_id' => $f->id, 'monto' => round($x, 2)]; $resta -= $x;
            }
            $cobro = $this->cobros->registrar($c, ['fecha' => today()->toDateString(), 'medios' => $medios, 'imputaciones' => $imp, 'notas' => $d['notas'] ?? null, 'cobrador_id' => Vendedor::deUsuario($u->id)?->id ?? $c->cobrador_id]);
            $cobro->forceFill(['modo' => 'calle', 'firma' => $d['firma'] ?? null, 'firma_nombre' => $d['firma_nombre'] ?? null, 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null])->save();
            $this->vender->visita($u, $c, ['tipo' => 'cobro', 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'nota' => 'Cobró ' . $cobro->numeroFormateado()]);
            AuditLog::registrar('cobro_calle', $cobro, "{$u->name} cobró {$cobro->numeroFormateado()} a {$c->name} por $ " . number_format($total, 2, ',', '.') . ' en la calle');
            return $cobro->fresh(['medios', 'imputaciones']);
        });
    }

    // Lo cobrado que todavía está a nombre del vendedor, separado por medio.
    public function enMano(User $u): array
    {
        $cobros = Cobro::with('medios')->where('user_id', $u->id)->where('modo', 'calle')->whereNull('rendicion_id')->where('estado', '!=', 'anulado')->orderBy('id')->get();
        $por = ['efectivo' => 0.0, 'transferencia' => 0.0, 'cheque' => 0.0];
        foreach ($cobros as $co) foreach ($co->medios as $m) if (isset($por[$m->medio])) $por[$m->medio] += (float) $m->monto;
        return ['cobros' => $cobros, 'efectivo' => round($por['efectivo'], 2), 'transferencias' => round($por['transferencia'], 2), 'cheques' => round($por['cheque'], 2), 'total' => round(array_sum($por), 2)];
    }

    // Cerrar el turno y rendir: agrupa todo lo cobrado desde la última rendición. No toca la plata: eso lo hace el encargado al recibirla.
    public function rendir(User $u, ?string $notas = null): RendicionCalle
    {
        return DB::transaction(function () use ($u, $notas) {
            $m = $this->enMano($u);
            if ($m['cobros']->isEmpty()) throw ValidationException::withMessages(['rendir' => 'No hay nada cobrado para rendir.']);
            $n = (int) RendicionCalle::withoutGlobalScopes()->where('business_id', $u->business_id)->max('numero') + 1;
            $r = RendicionCalle::create(['business_id' => $u->business_id, 'numero' => $n, 'vendedor_id' => Vendedor::deUsuario($u->id)?->id, 'user_id' => $u->id, 'efectivo' => $m['efectivo'], 'transferencias' => $m['transferencias'], 'cheques' => $m['cheques'], 'recibos' => $m['cobros']->count(), 'estado' => 'rendida', 'notas' => $notas, 'created_at' => now()]);
            Cobro::whereIn('id', $m['cobros']->pluck('id'))->update(['rendicion_id' => $r->id]);
            AuditLog::registrar('rendir_calle', $r, "{$u->name} rindió {$r->numeroFormateado()}: efectivo $ " . number_format($m['efectivo'], 2, ',', '.') . ", transferencias $ " . number_format($m['transferencias'], 2, ',', '.') . ", cheques $ " . number_format($m['cheques'], 2, ',', '.'));
            return $r;
        });
    }

    // El encargado recibe la plata: el efectivo contado pasa de "En mano" a la caja, las transferencias al banco, los cheques ya están en cartera.
    // La diferencia entre lo rendido y lo contado queda como faltante o sobrante del vendedor.
    public function recibir(RendicionCalle $r, User $encargado, array $d): RendicionCalle
    {
        return DB::transaction(function () use ($r, $encargado, $d) {
            $r = RendicionCalle::lockForUpdate()->findOrFail($r->id);
            if ($r->estado === 'recibida') throw ValidationException::withMessages(['rendir' => 'Esta rendición ya se recibió.']);
            $enMano = $this->cajaDe($r->user);
            $contado = round((float) ($d['efectivo_contado'] ?? $r->efectivo), 2);
            $dif = round($contado - $r->efectivo, 2);
            $ref = $r->numeroFormateado() . ' · ' . ($r->vendedor?->nombre ?? $r->user->name);
            if ($r->efectivo > 0.005) {
                $caja = CuentaFondos::where('tipo', 'caja')->findOrFail($d['caja_id']);
                if ($contado > 0.005) $this->fondos->transferir($enMano, $caja, $contado, today()->toDateString(), $ref);
                if (abs($dif) >= 0.01) $this->fondos->registrar($enMano, ['fecha' => today()->toDateString(), 'origen' => 'ajuste', 'concepto' => ($dif < 0 ? 'Faltante' : 'Sobrante') . " de rendición {$ref}", $dif < 0 ? 'egreso' : 'ingreso' => abs($dif), 'referencia' => $ref]);
            }
            if ($r->transferencias > 0.005) {
                $banco = CuentaFondos::findOrFail($d['banco_id']);
                $this->fondos->transferir($enMano, $banco, $r->transferencias, today()->toDateString(), $ref);
            }
            $r->forceFill(['estado' => 'recibida', 'efectivo_contado' => $contado, 'diferencia' => $dif, 'caja_id' => $d['caja_id'] ?? null, 'banco_id' => $d['banco_id'] ?? null, 'recibida_por' => $encargado->id, 'recibida_at' => now()])->save();
            AuditLog::registrar('recibir_rendicion', $r, "{$encargado->name} recibió {$r->numeroFormateado()}" . (abs($dif) >= 0.01 ? ' con ' . ($dif < 0 ? 'faltante' : 'sobrante') . ' de $ ' . number_format(abs($dif), 2, ',', '.') : ''));
            return $r;
        });
    }

    // Devolución desde la calle: nota de crédito con motivo. Las cantidades van en positivo; el signo lo pone el tipo de comprobante.
    public function devolucion(User $u, Contact $c, array $d): Comprobante
    {
        return DB::transaction(function () use ($u, $c, $d) {
            abort_unless($u->puede('comprobantes', 'crear'), 403, 'No tenés permiso para cargar comprobantes.');
            $cfg = $this->vender->config($u->business);
            $cond = app(CondicionesClienteService::class)->paraCliente($c);
            $items = collect($d['items'])->filter(fn($i) => (float) $i['cantidad'] > 0)->map(function ($i) use ($c, $cond) {
                $p = \App\Models\Product::findOrFail($i['product_id']);
                $pc = app(CondicionesClienteService::class)->para($c, $p, $cond);
                return ['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => $i['cantidad'], 'precio_unit' => $i['precio_unit'] ?? $pc['precio'], 'descuento' => $i['descuento'] ?? $pc['descuento'], 'alicuota_iva' => $p->iva];
            })->values()->all();
            if (! $items) throw ValidationException::withMessages(['items' => 'Elegí qué vuelve.']);
            $motivo = self::MOTIVOS_DEVOLUCION[$d['motivo']] ?? $d['motivo'];
            $nc = $this->comprobantes->guardarBorrador(['contact_id' => $c->id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'notas' => trim("Devolución: {$motivo}. " . ($d['nota'] ?? '')), 'items' => $items, 'vendedor_id' => Vendedor::deUsuario($u->id)?->id]);
            $nc->forceFill(['modo_venta' => 'calle', 'motivo_devolucion' => $d['motivo'], 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null])->save();
            if ($cfg['calle_emite']) $nc = $this->comprobantes->emitir($nc);
            AuditLog::registrar('devolucion_calle', $nc, "{$u->name} cargó una devolución de {$c->name}: {$motivo}");
            return $nc->fresh();
        });
    }

    // Confirmación de entrega: nombre de quien recibe (obligatorio), firma con el dedo y foto (con una de las dos alcanza).
    public function confirmarEntrega(Comprobante $c, User $u, array $d): Comprobante
    {
        if (! in_array($c->def()['grupo'] ?? '', ['factura', 'remito'], true) && $c->tipo !== 'REM') throw ValidationException::withMessages(['entrega' => 'Se confirma la entrega de una factura o un remito.']);
        $foto = null;
        if (! empty($d['foto'])) {
            // La foto llega como data URL desde el teléfono (también sin señal); se guarda como archivo privado de la empresa.
            if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $d['foto'], $m)) throw ValidationException::withMessages(['foto' => 'La foto tiene que ser JPG, PNG o WEBP.']);
            $bin = base64_decode($m[2], true);
            if ($bin === false || strlen($bin) > 4 * 1024 * 1024) throw ValidationException::withMessages(['foto' => 'La foto es muy grande (máximo 4 MB).']);
            $foto = "entregas/{$c->business_id}/{$c->id}-" . time() . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
            Storage::disk('local')->put($foto, $bin);
        }
        $c->forceFill(['entrega_nombre' => $d['nombre'], 'entrega_firma' => $d['firma'] ?? null, 'entrega_foto' => $foto ?? $c->entrega_foto, 'entregado_at' => ! empty($d['cuando']) ? \Carbon\Carbon::parse($d['cuando']) : now(), 'entregado_por' => $u->id])->save();
        // Entrega total: las facturas con entrega pendiente quedan entregadas.
        if ($c->entrega_pendiente) $c->items->each(fn($i) => $i->forceFill(['cantidad_entregada' => $i->cantidad])->save());
        AuditLog::registrar('entrega_confirmada', $c, "{$u->name} entregó {$c->nombreTipo()} " . ($c->estado === 'emitido' ? $c->numeroFormateado() : "#{$c->id}") . ": recibió {$d['nombre']}");
        return $c->fresh();
    }

    // Lo de hoy del vendedor: ventas (con su estado de entrega), cobros y lo que tiene en mano.
    public function hoy(User $u): array
    {
        $ventas = Comprobante::ventas()->where('user_id', $u->id)->where('modo_venta', 'calle')->whereDate('created_at', today())->where('estado', '!=', 'anulado')->with('contact:id,name')->orderByDesc('id')->get();
        $m = $this->enMano($u);
        return [
            'ventas' => $ventas->map(fn($c) => ['id' => $c->id, 'tipo' => $c->nombreTipo(), 'numero' => $c->estado === 'emitido' ? $c->numeroFormateado() : "#{$c->id}", 'cliente' => $c->contact?->name, 'contact_id' => $c->contact_id, 'total' => (float) $c->total, 'estado' => $c->estado, 'entregable' => in_array($c->tipo, ['FA', 'FB', 'FC', 'FE', 'REM'], true), 'entregado' => $c->entregado_at ? ['nombre' => $c->entrega_nombre, 'hora' => $c->entregado_at->format('H:i'), 'firma' => (bool) $c->entrega_firma, 'foto' => (bool) $c->entrega_foto] : null, 'es_nc' => $c->esNotaCredito()])->all(),
            'cobros' => $m['cobros']->map(fn($co) => ['id' => $co->id, 'numero' => $co->numeroFormateado(), 'cliente' => $co->contact?->name, 'total' => (float) $co->total, 'medios' => $co->medios->map(fn($x) => Cobro::MEDIOS[$x->medio] . ' ' . number_format((float) $x->monto, 2, ',', '.'))->implode(' · '), 'firmado' => (bool) $co->firma, 'hora' => $co->created_at->format('H:i')])->all(),
            'en_mano' => ['efectivo' => $m['efectivo'], 'transferencias' => $m['transferencias'], 'cheques' => $m['cheques'], 'total' => $m['total']],
            'ultima_rendicion' => ($r = RendicionCalle::where('user_id', $u->id)->orderByDesc('id')->first()) ? ['numero' => $r->numeroFormateado(), 'estado' => $r->estado, 'cuando' => $r->created_at->format('d/m H:i'), 'total' => round($r->efectivo + $r->transferencias + $r->cheques, 2)] : null,
        ];
    }

    // Para el encargado: rendiciones por recibir y las últimas recibidas.
    public function rendiciones(): array
    {
        $fila = fn(RendicionCalle $r) => ['id' => $r->id, 'numero' => $r->numeroFormateado(), 'vendedor' => $r->vendedor?->nombre ?? $r->user?->name, 'cuando' => $r->created_at->format('d/m H:i'), 'efectivo' => $r->efectivo, 'transferencias' => $r->transferencias, 'cheques' => $r->cheques, 'recibos' => $r->recibos, 'total' => round($r->efectivo + $r->transferencias + $r->cheques, 2), 'estado' => $r->estado, 'contado' => $r->efectivo_contado, 'diferencia' => $r->diferencia, 'recibida_por' => $r->recibidaPor?->name, 'recibida' => $r->recibida_at?->format('d/m H:i')];
        return [
            'pendientes' => RendicionCalle::with('vendedor', 'user', 'recibidaPor')->where('estado', 'rendida')->orderBy('id')->get()->map($fila)->all(),
            'recibidas' => RendicionCalle::with('vendedor', 'user', 'recibidaPor')->where('estado', 'recibida')->orderByDesc('id')->limit(20)->get()->map($fila)->all(),
            'en_mano' => CuentaFondos::whereNotNull('vendedor_id')->where('saldo', '>', 0.005)->with('vendedor:id,nombre')->get()->map(fn($c) => ['vendedor' => $c->vendedor?->nombre ?? $c->nombre, 'saldo' => (float) $c->saldo])->all(),
        ];
    }
}
