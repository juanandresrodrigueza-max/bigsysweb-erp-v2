<template>
  <component :is="bloqueada ? 'div' : AppLayout" titulo="Suscripción" :class="bloqueada ? 'min-h-screen bg-marca-fondo' : ''">
    <Head v-if="bloqueada" title="Suscripción" />
    <div v-if="bloqueada" class="h-16 bg-white border-b border-marca-borde flex items-center px-6 gap-3"><Logo clase="h-7" /><span class="flex-1"></span><Link href="/logout" method="post" as="button" class="btn-ghost text-xs"><Icono nombre="logout" clase="w-4 h-4" /> Salir</Link></div>
    <div :class="bloqueada ? 'max-w-5xl mx-auto p-4 md:p-8' : ''">

      <div v-if="bloqueada" class="rounded-2xl p-6 mb-6 text-white bg-marca-grad shadow-lg">
        <p class="text-[11px] font-bold uppercase tracking-widest opacity-80">Acceso suspendido</p>
        <h1 class="text-2xl font-extrabold mt-1">{{ motivo }}</h1>
        <p v-if="administrativa" class="mt-2 text-white/90 max-w-2xl">Tus datos están intactos. Esta suspensión no depende del pago: para reactivar el acceso escribinos a {{ soporte.whatsapp }} o {{ soporte.email }}.</p>
        <p v-else class="mt-2 text-white/90 max-w-2xl">Tus datos están intactos. {{ esDueno ? 'Renová el plan acá abajo y el acceso vuelve al instante.' : 'Avisale al dueño de la empresa para que renueve el plan.' }} Si creés que es un error, escribinos a {{ soporte.whatsapp }} o {{ soporte.email }}.</p>
      </div>
      <div v-else class="flex flex-wrap items-end justify-between gap-3 mb-5">
        <div><Link href="/configuracion" class="text-xs text-marca-muted hover:text-carmin">← Configuración</Link><h1 class="page-title">Suscripción</h1><p class="page-subtitle">Tu plan, el vencimiento y los pagos.</p></div>
      </div>

      <div class="grid lg:grid-cols-3 gap-4 mb-6">
        <div class="card lg:col-span-2">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Plan actual</p>
              <p class="text-3xl font-black mt-1">{{ actual?.plan ?? 'Sin plan' }}<span v-if="actual" class="text-base font-semibold text-marca-muted"> · {{ cicloLabel[actual.ciclo] }}</span></p>
              <span v-if="actual" class="badge mt-2" :class="estadoClase[actual.estado]">{{ actual.estado_label }}</span>
            </div>
            <div v-if="actual" class="text-right"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ actual.estado === 'grace' ? 'Suspende el' : 'Vence' }}</p><p class="text-2xl font-extrabold tabular-nums" :class="actual.dias !== null && actual.dias <= 3 ? 'text-carmin' : ''">{{ actual.vence ?? '—' }}</p><p v-if="actual.dias !== null" class="text-xs text-marca-muted">{{ actual.dias < 0 ? `hace ${-actual.dias} días` : actual.dias === 0 ? 'hoy' : `en ${actual.dias} días` }}</p></div>
          </div>
          <div v-if="actual?.aviso" class="mt-4 px-4 py-3 rounded-xl text-sm" :class="{ info: 'bg-lavanda-light text-violeta', warn: 'bg-amber-50 text-amber-800', error: 'bg-carmin-light text-carmin-dark' }[actual.aviso.nivel]">{{ actual.aviso.texto }}</div>
          <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-marca-muted text-xs">Usuarios</p><p class="font-bold">{{ uso.usuarios }}<span class="text-marca-muted font-normal"> / {{ limite('usuarios') }}</span></p></div>
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-marca-muted text-xs">Sucursales</p><p class="font-bold">{{ uso.sucursales }}<span class="text-marca-muted font-normal"> / {{ limite('sucursales') }}</span></p></div>
            <div class="p-3 rounded-xl bg-marca-fondo" data-e2e="uso-facturas"><p class="text-marca-muted text-xs">Facturas este mes</p><p class="font-bold" :class="cerca('facturas') ? 'text-carmin' : ''">{{ uso.facturas }}<span class="text-marca-muted font-normal"> / {{ limite('facturas') }}</span></p></div>
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-marca-muted text-xs">Artículos</p><p class="font-bold" :class="cerca('articulos') ? 'text-carmin' : ''">{{ uso.articulos }}<span class="text-marca-muted font-normal"> / {{ limite('articulos') }}</span></p></div>
          </div>
        </div>
        <div class="card">
          <h2 class="font-bold mb-2">Últimos pagos</h2>
          <div v-for="p in pagos.slice(0, 6)" :key="p.id" class="py-2 border-t border-marca-borde/60 first:border-0 text-sm">
            <div class="flex justify-between"><span>{{ p.fecha }} · {{ p.plan }}</span><b class="tabular-nums">{{ moneda(p.monto, 0) }}</b></div>
            <div class="flex justify-between mt-0.5"><span class="text-xs text-marca-muted">{{ p.medio }}<span v-if="p.periodo"> · {{ p.periodo }}</span></span><span class="badge" :class="pagoClase[p.estado]">{{ pagoLabel[p.estado] }}</span></div>
          </div>
          <p v-if="!pagos.length" class="text-sm text-marca-muted">Todavía no hay pagos.</p>
        </div>
      </div>

      <template v-if="esDueno && !administrativa">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
          <h2 class="text-lg font-extrabold">{{ bloqueada ? 'Renovar' : 'Renovar o cambiar de plan' }}</h2>
          <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1">
            <button v-for="c in [['monthly','Mensual'],['yearly','Anual']]" :key="c[0]" @click="pago.ciclo = c[0]" class="px-4 py-1.5 rounded-full text-xs font-semibold" :class="pago.ciclo === c[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ c[1] }}<span v-if="c[0] === 'yearly'" class="ml-1 opacity-80">(2 meses gratis)</span></button>
          </div>
        </div>
        <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
          <button v-for="p in planes" :key="p.id" type="button" @click="pago.plan_id = p.id" class="card text-left flex flex-col transition" :class="pago.plan_id === p.id ? 'ring-2 ring-carmin border-transparent' : 'hover:border-carmin/40'">
            <div class="flex items-center justify-between"><h3 class="text-lg font-extrabold">{{ p.nombre }}</h3><span v-if="actual?.plan_id === p.id" class="badge bg-lavanda-light text-violeta">Tu plan</span></div>
            <p class="text-xs text-marca-muted min-h-[2rem] mt-1">{{ p.descripcion }}</p>
            <p class="mt-3"><span class="text-2xl font-black tabular-nums">{{ moneda(pago.ciclo === 'yearly' ? p.anual : p.mensual, 0) }}</span><span class="text-xs text-marca-muted"> /{{ pago.ciclo === 'yearly' ? 'año' : 'mes' }}</span></p>
            <ul class="mt-3 text-xs space-y-1 text-marca-muted flex-1">
              <li>{{ p.usuarios < 0 ? 'Usuarios sin límite' : `Hasta ${p.usuarios} usuario${p.usuarios === 1 ? '' : 's'}` }}</li>
              <li>{{ p.sucursales < 0 ? 'Sucursales sin límite' : `${p.sucursales} sucursal${p.sucursales === 1 ? '' : 'es'}` }}</li>
              <li>{{ p.facturas < 0 ? 'Facturas sin límite' : `Hasta ${p.facturas.toLocaleString('es-AR')} facturas por mes` }}</li>
              <li>{{ p.articulos < 0 ? 'Artículos sin límite' : `Hasta ${p.articulos.toLocaleString('es-AR')} artículos` }}</li>
              <li v-for="m in p.modulos" :key="m" class="flex items-center gap-1 text-marca-texto"><Icono nombre="check" clase="w-3 h-3 text-emerald-600" /> {{ m }}</li>
            </ul>
          </button>
        </div>

        <div class="card">
          <div class="grid lg:grid-cols-2 gap-5">
            <div>
              <p class="label">Cómo querés pagar</p>
              <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer mb-2" :class="pago.medio === 'mercadopago' ? 'border-carmin bg-carmin-light/40' : 'border-marca-borde'">
                <input v-model="pago.medio" type="radio" value="mercadopago" class="accent-carmin mt-1" />
                <span><b>MercadoPago</b><span class="block text-xs text-marca-muted">Tarjeta, débito o dinero en cuenta. Se activa al instante.<span v-if="!mercadopago"> Modo de prueba: se aprueba solo.</span></span></span>
              </label>
              <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer" :class="pago.medio === 'transferencia' ? 'border-carmin bg-carmin-light/40' : 'border-marca-borde'">
                <input v-model="pago.medio" type="radio" value="transferencia" class="accent-carmin mt-1" />
                <span><b>Transferencia bancaria</b><span class="block text-xs text-marca-muted">Transferís y nos avisás acá. Se activa cuando la confirmamos (mismo día hábil).</span></span>
              </label>
              <div v-if="pago.medio === 'transferencia'" class="mt-3 p-3 rounded-xl bg-marca-fondo text-sm space-y-1">
                <p><span class="text-marca-muted">Titular:</span> <b>{{ transferencia.titular }}</b></p>
                <p v-if="transferencia.cbu"><span class="text-marca-muted">CBU:</span> <b class="tabular-nums">{{ transferencia.cbu }}</b></p>
                <p><span class="text-marca-muted">Alias:</span> <b>{{ transferencia.alias }}</b></p>
                <input v-model="pago.referencia" class="input mt-2" placeholder="N° de operación o banco desde el que transferiste" />
              </div>
            </div>
            <div class="flex flex-col justify-between">
              <div class="p-4 rounded-xl bg-marca-fondo text-sm space-y-1">
                <div class="flex justify-between"><span class="text-marca-muted">Plan</span><b>{{ planElegido?.nombre }} · {{ cicloLabel[pago.ciclo] }}</b></div>
                <div class="flex justify-between text-lg font-extrabold pt-1 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(total) }}</span></div>
                <p class="text-xs text-marca-muted">{{ actual?.estado === 'active' && actual.dias > 0 ? 'Se suma a partir de tu vencimiento actual.' : 'Arranca hoy.' }}</p>
              </div>
              <button class="btn-primary w-full mt-4 !py-3 text-base" :disabled="pago.processing || !pago.plan_id" @click="pago.post('/suscripcion/pagar')">{{ pago.processing ? 'Un momento…' : (pago.medio === 'mercadopago' ? 'Pagar con MercadoPago' : 'Avisar transferencia') }}</button>
            </div>
          </div>
        </div>
      </template>

      <p class="text-xs text-marca-muted text-center mt-6">¿Dudas? {{ soporte.whatsapp }} · {{ soporte.email }}</p>
    </div>
  </component>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Logo from '@/Components/Logo.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
import { estadoClase, pagoClase, pagoLabel, cicloLabel } from '@/util/suscripcion'
const props = defineProps({ bloqueada: Boolean, motivo: String, administrativa: Boolean, esDueno: Boolean, actual: Object, uso: Object, planes: Array, pagos: Array, mercadopago: Boolean, transferencia: Object, soporte: Object })
const pago = useForm({ plan_id: props.actual?.plan_id ?? props.planes.find(p => !p.gratis)?.id, ciclo: props.actual?.ciclo ?? 'monthly', medio: 'mercadopago', referencia: '' })
const planElegido = computed(() => props.planes.find(p => p.id === pago.plan_id))
const total = computed(() => planElegido.value ? (pago.ciclo === 'yearly' ? planElegido.value.anual : planElegido.value.mensual) : 0)
// Uso al 80 % o más del límite del plan.
const cerca = k => { const p = props.planes.find(x => x.id === props.actual?.plan_id); const v = p?.[k]; return v > 0 && (props.uso?.[k] ?? 0) >= v * 0.8 }
const limite = k => { const p = props.planes.find(x => x.id === props.actual?.plan_id); const v = p?.[k]; return v === undefined ? '—' : v < 0 ? '∞' : v }
</script>
