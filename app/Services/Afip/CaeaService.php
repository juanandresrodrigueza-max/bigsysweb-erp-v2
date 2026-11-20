<?php

namespace App\Services\Afip;

use App\Models\Alerta;
use App\Models\ArcaCaea;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Comprobante;
use App\Models\PuntoVenta;
use App\Models\SistemaConfig;
use App\Services\Comprobantes\AfipEmisor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

// Fase 126: contingencia con CAEA (Código de Autorización Electrónico Anticipado).
//
// ARCA da un CAEA por quincena (del 1 al 15 y del 16 a fin de mes) y por CUIT. Se pide hasta 5 días antes de que empiece
// la quincena. Cuando una empresa lo activa, el sistema lo pide solo. Si al facturar ARCA no responde, en vez de dejar la
// factura «pendiente de CAE», la emite al momento con el CAEA, en un punto de venta aparte dado de alta en ARCA para CAEA
// (modo «caea» en Configuración → Puntos de venta). Esa factura es válida desde que sale. Después hay que informarla
// (FECAEARegInformativo, con la fecha y hora en que se generó), y eso se hace solo apenas ARCA vuelve. Al terminar la
// quincena, los puntos de venta CAEA que no se usaron se informan «sin movimiento». Ambas cosas tienen fecha tope.
//
// Normas: RG 2485 y RG 4291 (régimen del CAEA). RG 5782/2025 y RG 5785/2025 lo dejan sólo para contingencias y como
// primera opción de contingencia de los web services. Las fechas de vigencia las fija ARCA (RG 5852/2026 las postergó).
class CaeaService
{
    public const DIAS_ANTES = 5;   // se puede pedir desde 5 días corridos antes de que empiece la quincena
    public const AVISO_TOPE = 2;   // días antes del tope para informar en que se avisa como crítico

    // ─── Quincenas ───
    public static function quincena(Carbon $f): array { return [(int) $f->format('Ym'), $f->day <= 15 ? 1 : 2]; }
    public static function siguiente(Carbon $f): array { return $f->day <= 15 ? [(int) $f->format('Ym'), 2] : [(int) $f->copy()->startOfMonth()->addMonthNoOverflow()->format('Ym'), 1]; }
    public static function inicio(int $periodo, int $orden): Carbon { $d = Carbon::createFromFormat('Ymd', $periodo . '01')->startOfDay(); return $orden === 1 ? $d : $d->day(16); }
    public static function fin(int $periodo, int $orden): Carbon { $d = Carbon::createFromFormat('Ymd', $periodo . '01')->startOfDay(); return $orden === 1 ? $d->day(15) : $d->endOfMonth()->startOfDay(); }

    // ─── Con qué CUIT y ambiente ───
    public static function cuit(Business $emisor): string { return preg_replace('/\D/', '', (string) $emisor->cuit); }
    public static function produccion(Business $emisor): bool
    {
        return app(AfipEmisor::class)->modo($emisor) === 'bigsys' ? (bool) SistemaConfig::get('arca_produccion') : (bool) $emisor->afip_produccion;
    }

    public function vigente(Business $emisor, Carbon $fecha): ?ArcaCaea
    {
        return ArcaCaea::where('cuit', self::cuit($emisor))->where('produccion', self::produccion($emisor))
            ->whereDate('desde', '<=', $fecha->toDateString())->whereDate('hasta', '>=', $fecha->toDateString())->orderByDesc('id')->first();
    }

    // El punto de venta de contingencia: el de la sucursal si factura con CUIT propio; si no, el de la empresa.
    public function puntoVenta(Business $b, ?BusinessLocation $loc): ?PuntoVenta
    {
        $propia = $loc?->tieneCuitPropio() ? $loc->id : null;
        return PuntoVenta::withoutGlobalScopes()->with(['location' => fn($q) => $q->withoutGlobalScopes()])->where('business_id', $b->id)->where('modo', 'caea')->where('activo', true)->orderBy('numero')->get()
            ->first(fn($p) => $propia ? $p->business_location_id === $propia : ! $p->location?->tieneCuitPropio());
    }

    // ─── Pedir el CAEA de una quincena (si ya se pidió, ARCA no da otro: se consulta) ───
    public function obtener(Business $emisor, int $periodo, int $orden): ArcaCaea
    {
        $cuit = self::cuit($emisor); $prod = self::produccion($emisor);
        if ($ya = ArcaCaea::where(compact('cuit', 'periodo', 'orden'))->where('produccion', $prod)->first()) return $ya;
        $afip = AfipService::forBusiness($emisor);
        try { $r = $afip->caeaSolicitar($periodo, $orden); }
        catch (\Throwable $e) {
            if (AfipErrores::esConexion($e->getMessage())) throw $e;
            $r = $afip->caeaConsultar($periodo, $orden);
            if (! $r) throw $e;
        }
        $fecha = fn($v) => $v ? Carbon::createFromFormat('Ymd', (string) $v)->startOfDay() : null;
        $c = ArcaCaea::create(['business_id' => $emisor->id, 'cuit' => $cuit, 'produccion' => $prod, 'periodo' => $periodo, 'orden' => $orden, 'caea' => (string) $r['CAEA'],
            'desde' => $fecha($r['FchVigDesde'] ?? null) ?? self::inicio($periodo, $orden), 'hasta' => $fecha($r['FchVigHasta'] ?? null) ?? self::fin($periodo, $orden), 'tope_informar' => $fecha($r['FchTopeInf'] ?? null)]);
        AuditLog::registrar('editar', Business::find($emisor->id), "ARCA otorgó el CAEA {$c->caea} para la {$c->quincena()} (CUIT {$cuit})");
        return $c;
    }

    // Las empresas (y sucursales con CUIT propio) que facturan con ARCA y tienen la contingencia activa y un punto de venta CAEA.
    public function emisores(?int $businessId = null): array
    {
        $out = [];
        foreach (Business::where('arca_caea', true)->when($businessId, fn($q) => $q->whereKey($businessId))->get() as $b) {
            $locs = BusinessLocation::withoutGlobalScopes()->where('business_id', $b->id)->get()->filter(fn($l) => $l->tieneCuitPropio());
            foreach (array_merge([null], $locs->all()) as $loc) {
                $e = $b->emisorPara($loc);
                if (app(AfipEmisor::class)->configurado($e) && $this->puntoVenta($b, $loc)) $out[] = [$b, $loc, $e];
            }
        }
        return $out;
    }

    // Todos los días: el de la quincena en curso si falta, y el de la próxima dentro de los 5 días anteriores a que empiece.
    public function pedirProximos(?int $businessId = null): array
    {
        $hoy = today(); $out = [];
        foreach ($this->emisores($businessId) as [$b, $loc, $e]) {
            $quincenas = [self::quincena($hoy)];
            [$p, $o] = self::siguiente($hoy);
            if ($hoy->gte(self::inicio($p, $o)->subDays(self::DIAS_ANTES))) $quincenas[] = [$p, $o];
            foreach ($quincenas as [$p, $o]) {
                try { $c = $this->obtener($e, $p, $o); $out[] = ['empresa' => $b->name, 'cuit' => self::cuit($e), 'periodo' => $p, 'orden' => $o, 'caea' => $c->caea, 'ok' => true]; }
                catch (\Throwable $ex) { Log::warning("CAEA {$b->name} {$p}/{$o}: " . $ex->getMessage()); $out[] = ['empresa' => $b->name, 'cuit' => self::cuit($e), 'periodo' => $p, 'orden' => $o, 'ok' => false, 'error' => $ex->getMessage()]; }
            }
        }
        return $out;
    }

    // ─── Al emitir: ARCA no respondió → con CAEA, si se puede ───
    // Devuelve el resultado como el de AfipEmisor::emitir (aprobado, con número y código) o null si no hay contingencia posible.
    public function contingencia(Comprobante $c, string $motivo): ?array
    {
        $b = $c->business;
        if (! $b?->arca_caea || ! $c->afipTipo() || $c->esExportacion()) return null;
        $e = $c->emisor();
        $caea = $this->vigente($e, $c->fecha); if (! $caea) return null;
        $pv = $this->puntoVenta($b, $c->location); if (! $pv) return null;
        $numero = $pv->proximoNumero($c->tipo);
        $c->punto_venta = $pv->numero; $c->punto_venta_id = $pv->id;
        $data = app(AfipEmisor::class)->armarDatos($c, $c->afipTipo(), $numero);
        return ['estado' => 'aprobado', 'numero' => $numero, 'cae' => $caea->caea, 'cae_vto' => $caea->hasta, 'cae_tipo' => 'CAEA',
            'respuesta' => ['caea' => true, 'caea_id' => $caea->id, 'generado' => now()->format('YmdHis'), 'enviado' => $data, 'motivo' => mb_substr($motivo, 0, 300)]];
    }

    // ─── Después: informar a ARCA lo emitido con CAEA ───
    public function informar(Comprobante $c): array
    {
        $r = $c->afip_respuesta ?? [];
        $data = array_merge($r['enviado'] ?? [], ['CAEA' => $c->cae, 'CbteFchHsGen' => $r['generado'] ?? $c->emitido_en?->format('YmdHis')]);
        try {
            $res = AfipService::forBusiness($c->emisor())->caeaInformar($data);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (AfipErrores::esConexion($msg)) return ['estado' => 'pendiente', 'error' => $msg];
            $c->forceFill(['afip_respuesta' => array_merge($r, ['error_informe' => mb_substr($msg, 0, 500), 'intentos_informe' => (int) ($r['intentos_informe'] ?? 0) + 1])])->save();
            Alerta::emitir(['business_id' => $c->business_id, 'modulo' => 'comprobantes', 'tipo' => 'caea_rechazado', 'modelo' => 'Comprobante', 'modelo_id' => $c->id, 'severidad' => 'critica',
                'titulo' => "ARCA rechazó informar {$c->nombreTipo()} {$c->numeroFormateado()} (CAEA)", 'detalle' => mb_substr(AfipErrores::explicar($msg)['que'] . ' Detalle: ' . $msg . ' Avisá a soporte de BigSys: hay que corregirlo antes de la fecha tope.', 0, 600), 'url' => "/comprobantes/{$c->id}"]);
            return ['estado' => 'rechazado', 'error' => $msg];
        }
        unset($r['error_informe']);
        $c->forceFill(['caea_informado_en' => now(), 'afip_respuesta' => array_merge($r, ['informado' => now()->toIso8601String(), 'obs_informe' => $res['Observaciones'] ?? []])])->save();
        Alerta::withoutGlobalScopes()->where('modelo', 'Comprobante')->where('modelo_id', $c->id)->whereIn('tipo', ['caea_rechazado', 'caea_tope'])->update(['resuelta_en' => now()]);
        AuditLog::registrar('emitir', $c, "Se informó a ARCA {$c->nombreTipo()} {$c->numeroFormateado()} emitido con CAEA");
        return ['estado' => 'informado'];
    }

    public function informarPendientes(?int $businessId = null): array
    {
        $n = ['informados' => 0, 'pendientes' => 0, 'rechazados' => 0];
        $q = Comprobante::withoutGlobalScopes()->where('cae_tipo', 'CAEA')->whereNull('caea_informado_en')->where('estado', 'emitido')->when($businessId, fn($q) => $q->where('business_id', $businessId))->orderBy('id');
        foreach ($q->cursor() as $c) {
            $r = $this->informar($c);
            $n[$r['estado'] === 'informado' ? 'informados' : ($r['estado'] === 'rechazado' ? 'rechazados' : 'pendientes')]++;
            if ($r['estado'] !== 'informado') $this->avisarTope($c);
        }
        return $n;
    }

    private function avisarTope(Comprobante $c): void
    {
        $caea = ArcaCaea::find($c->afip_respuesta['caea_id'] ?? 0) ?? ArcaCaea::where('caea', $c->cae)->first();
        if (! $caea?->tope_informar || today()->lt($caea->tope_informar->copy()->subDays(self::AVISO_TOPE))) return;
        Alerta::emitir(['business_id' => $c->business_id, 'modulo' => 'comprobantes', 'tipo' => 'caea_tope', 'modelo' => 'Comprobante', 'modelo_id' => $c->id, 'severidad' => 'critica',
            'titulo' => "{$c->nombreTipo()} {$c->numeroFormateado()} con CAEA sin informar a ARCA", 'detalle' => "La fecha tope para informarlo es el {$caea->tope_informar->format('d/m/Y')}. El sistema reintenta solo; si ARCA lo sigue rechazando o sin responder, avisá a soporte de BigSys.", 'url' => "/comprobantes/{$c->id}"]);
    }

    // ─── Terminada la quincena: los puntos de venta CAEA que no se usaron, «sin movimiento» ───
    public function informarSinMovimiento(?int $businessId = null): array
    {
        $out = [];
        foreach ($this->emisores($businessId) as [$b, $loc, $e]) {
            $pv = $this->puntoVenta($b, $loc);
            $caeas = ArcaCaea::where('cuit', self::cuit($e))->where('produccion', self::produccion($e))->whereDate('hasta', '<', today()->toDateString())->where('created_at', '>=', now()->subMonths(3))->get();
            foreach ($caeas as $caea) {
                $ya = $caea->sin_movimiento ?? [];
                if (isset($ya[(string) $pv->numero])) continue;
                $usado = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('cae_tipo', 'CAEA')->where('cae', $caea->caea)->where('punto_venta', $pv->numero)->exists();
                if ($usado) continue;
                try {
                    AfipService::forBusiness($e)->caeaSinMovimiento((int) $pv->numero, $caea->caea);
                    $caea->forceFill(['sin_movimiento' => $ya + [(string) $pv->numero => now()->toDateTimeString()]])->save();
                    $out[] = ['empresa' => $b->name, 'pv' => $pv->numero, 'caea' => $caea->caea, 'ok' => true];
                } catch (\Throwable $ex) {
                    // ARCA no deja informarlo dos veces: si dice que ya está, queda anotado.
                    if (preg_match('/ya (fue )?informad|ya existe|existe un registro/i', $ex->getMessage())) { $caea->forceFill(['sin_movimiento' => $ya + [(string) $pv->numero => now()->toDateTimeString()]])->save(); continue; }
                    Log::warning("CAEA sin movimiento {$b->name} PV {$pv->numero}: " . $ex->getMessage());
                    $out[] = ['empresa' => $b->name, 'pv' => $pv->numero, 'caea' => $caea->caea, 'ok' => false, 'error' => $ex->getMessage()];
                }
            }
        }
        return $out;
    }

    // ─── Para la pantalla de la empresa ───
    public function estado(Business $b): array
    {
        $e = $b->emisorPara(null); $pv = $this->puntoVenta($b, null); $hoy = today();
        $fila = fn(?ArcaCaea $c) => $c ? ['caea' => $c->caea, 'quincena' => $c->quincena(), 'desde' => $c->desde->format('d/m/Y'), 'hasta' => $c->hasta->format('d/m/Y'), 'tope' => $c->tope_informar?->format('d/m/Y')] : null;
        [$p, $o] = self::siguiente($hoy);
        $prox = ArcaCaea::where('cuit', self::cuit($e))->where('produccion', self::produccion($e))->where('periodo', $p)->where('orden', $o)->first();
        $sinInformar = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('cae_tipo', 'CAEA')->whereNull('caea_informado_en')->where('estado', 'emitido');
        return [
            'activo' => (bool) $b->arca_caea, 'pv' => $pv?->numero, 'configurado' => app(AfipEmisor::class)->configurado($e),
            'vigente' => $fila($this->vigente($e, $hoy)), 'proximo' => $fila($prox), 'proximo_desde' => self::inicio($p, $o)->subDays(self::DIAS_ANTES)->format('d/m/Y'),
            'sin_informar' => (clone $sinInformar)->count(), 'rechazados' => (clone $sinInformar)->get()->filter(fn($c) => ! empty($c->afip_respuesta['error_informe']))->count(),
            'emitidos' => Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('cae_tipo', 'CAEA')->where('estado', 'emitido')->count(),
        ];
    }
}
