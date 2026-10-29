<?php

namespace App\Services\Empresas;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ConjuntoEmpresas;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// Fase 39.2: a qué empresas entra cada persona (todas o algunas, validado en el servidor) y los conjuntos que se miran juntos:
// el tablero suma las empresas, saca las ventas entre ellas (por CUIT) y avisa cuál está atrasada.
class GrupoService
{
    // Las empresas donde esta persona manda: es la dueña o tiene el rol Dueño o Administrador.
    public function dondeManda(User $u): \Illuminate\Support\Collection
    {
        return $u->empresasAccesibles()->filter(function (Business $b) use ($u) {
            if ($b->owner_id === $u->id || $u->is_superadmin) return true;
            $rid = $b->id === $u->business_id ? $u->role_id : $u->empresas()->where('businesses.id', $b->id)->first()?->pivot?->role_id;
            return in_array(Role::withoutGlobalScopes()->find($rid)?->slug, ['dueno', 'administrador'], true);
        })->values();
    }

    // Las empresas extra de una persona, con el perfil en cada una. Sólo se tocan las empresas donde manda quien lo hace.
    public function accesos(User $quien, User $persona, array $empresas): void
    {
        $permitidas = $this->dondeManda($quien)->pluck('id')->all();
        $pedidas = collect($empresas)->filter(fn($e) => ! empty($e['business_id']))->keyBy(fn($e) => (int) $e['business_id']);
        foreach ($pedidas->keys() as $bid) if (! in_array($bid, $permitidas, true)) throw ValidationException::withMessages(['empresas' => 'No podés dar acceso a una empresa que no manejás.']);
        foreach ($permitidas as $bid) {
            if ($bid === $persona->business_id) continue; // la empresa propia se maneja con el rol de siempre
            if ($e = $pedidas->get($bid)) {
                $rol = Role::withoutGlobalScopes()->where('business_id', $bid)->where('slug', $e['perfil'] ?? 'solo_mirar')->first();
                if (! $rol) throw ValidationException::withMessages(['empresas' => 'Ese perfil no existe en una de las empresas.']);
                $persona->empresas()->syncWithoutDetaching([$bid => ['role_id' => $rol->id]]);
            } else {
                $persona->empresas()->detach($bid);
            }
        }
        AuditLog::registrar('editar', $persona, "Empresas de {$persona->email}: " . $pedidas->count() . ' además de la propia');
    }

    public function guardarConjunto(User $u, ?int $id, string $nombre, array $empresas): ConjuntoEmpresas
    {
        $acc = $u->empresasAccesibles()->pluck('id')->all();
        $ids = array_values(array_unique(array_map('intval', $empresas)));
        if (count($ids) < 2) throw ValidationException::withMessages(['empresas' => 'Un conjunto necesita al menos dos empresas.']);
        if (array_diff($ids, $acc)) throw ValidationException::withMessages(['empresas' => 'Hay empresas a las que no tenés acceso.']);
        $c = $id ? ConjuntoEmpresas::where('user_id', $u->id)->findOrFail($id) : new ConjuntoEmpresas(['user_id' => $u->id]);
        $c->fill(['nombre' => $nombre, 'empresas' => $ids])->save();
        return $c;
    }

    // El tablero del conjunto: cada empresa y el total, sin contar dos veces lo que se venden entre ellas.
    public function tablero(User $u, ConjuntoEmpresas $c, string $mes): array
    {
        abort_if($c->user_id !== $u->id, 404);
        $acc = $u->empresasAccesibles()->pluck('id')->all();
        $ids = array_values(array_intersect($c->empresas, $acc)); // si le sacaron el acceso, deja de sumarla
        $empresas = Business::whereIn('id', $ids)->get();
        $cuits = $empresas->mapWithKeys(fn($b) => [preg_replace('/\D/', '', (string) $b->cuit) => $b])->filter(fn($b, $k) => strlen((string) $k) === 11);
        [$desde, $hasta] = [\Carbon\Carbon::parse($mes . '-01')->toDateString(), \Carbon\Carbon::parse($mes . '-01')->endOfMonth()->toDateString()];
        $tipos = ['FA', 'FB', 'FC', 'FE', 'FX', 'NDA', 'NDB', 'NDC'];
        $nc = ['NCA', 'NCB', 'NCC', 'NCX'];
        $neto = fn($q) => (float) (clone $q)->whereIn('tipo', $tipos)->sum('total') - (float) (clone $q)->whereIn('tipo', $nc)->sum('total');
        $filas = []; $entre = [];
        foreach ($empresas as $b) {
            $v = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta]);
            $ventas = $neto($v);
            // Ventas a otra empresa del conjunto: el cliente tiene el CUIT de una de ellas.
            $clientesGrupo = Contact::withoutGlobalScopes()->where('business_id', $b->id)->get(['id', 'cuit'])
                ->filter(fn($x) => ($k = preg_replace('/\D/', '', (string) $x->cuit)) && isset($cuits[$k]) && $cuits[$k]->id !== $b->id);
            $intra = 0;
            foreach ($clientesGrupo as $x) {
                $a = $cuits[preg_replace('/\D/', '', (string) $x->cuit)];
                $m = $neto((clone $v)->where('contact_id', $x->id));
                if ($m) { $intra += $m; $entre[] = ['de' => $b->name, 'a' => $a->name, 'monto' => round($m, 2)]; }
            }
            $compras = (float) Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])->sum('total');
            $ultVenta = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->max('fecha');
            $ultCompra = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->max('fecha');
            $dv = $ultVenta ? (int) \Carbon\Carbon::parse($ultVenta)->diffInDays(today()) : null;
            $dc = $ultCompra ? (int) \Carbon\Carbon::parse($ultCompra)->diffInDays(today()) : null;
            $atrasos = [];
            if ($dv === null) $atrasos[] = 'nunca cargó ventas'; elseif ($dv > 7) $atrasos[] = "sin ventas cargadas hace {$dv} días";
            if ($dc === null) $atrasos[] = 'nunca cargó compras'; elseif ($dc > 30) $atrasos[] = "sin compras cargadas hace {$dc} días";
            $filas[] = [
                'id' => $b->id, 'nombre' => $b->name, 'cuit' => $b->cuit, 'ventas' => round($ventas, 2), 'entre_grupo' => round($intra, 2), 'compras' => round($compras, 2),
                'cobrar' => round((float) Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('type', ['customer', 'both'])->where('balance', '>', 0)->sum('balance'), 2),
                'pagar' => round((float) Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('type', ['supplier', 'both'])->where('balance', '>', 0)->sum('balance'), 2),
                'fondos' => round((float) CuentaFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('activa', true)->sum('saldo'), 2),
                'ultima_venta' => $ultVenta ? \Carbon\Carbon::parse($ultVenta)->format('d/m/Y') : null, 'atrasos' => $atrasos,
            ];
        }
        $col = fn($k) => round(array_sum(array_column($filas, $k)), 2);
        return [
            'conjunto' => ['id' => $c->id, 'nombre' => $c->nombre], 'mes' => $mes, 'empresas' => $filas, 'entre' => $entre,
            'total' => ['ventas' => $col('ventas'), 'entre_grupo' => $col('entre_grupo'), 'ventas_afuera' => round($col('ventas') - $col('entre_grupo'), 2), 'compras' => $col('compras'), 'cobrar' => $col('cobrar'), 'pagar' => $col('pagar'), 'fondos' => $col('fondos')],
            'atrasadas' => collect($filas)->filter(fn($f) => $f['atrasos'])->map(fn($f) => ['nombre' => $f['nombre'], 'texto' => implode(' · ', $f['atrasos'])])->values()->all(),
            'sin_acceso' => count($c->empresas) - count($ids),
        ];
    }
}
