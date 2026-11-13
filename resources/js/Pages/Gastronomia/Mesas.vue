<template>
  <AppLayout titulo="Big Restó · Salón">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><p class="text-xs font-bold uppercase tracking-widest text-carmin">Big Restó</p><h1 class="page-title">Salón</h1><p class="page-subtitle">Tocá una mesa para abrirla o ver su comanda. Se actualiza solo.</p></div>
      <div class="flex flex-wrap gap-2">
        <Link href="/gastronomia/carta" class="btn-secondary" data-e2e="ir-carta">Carta</Link>
        <Link href="/gastronomia/llamador" class="btn-secondary" data-e2e="ir-llamador">Llamador</Link>
        <Link href="/gastronomia/reservas" class="btn-secondary">Reservas y QR</Link>
        <Link href="/gastronomia/cocina" class="btn-secondary"><Icono nombre="utensils" clase="w-4 h-4" /> Cocina <span v-if="kpis.en_cocina" class="ml-1 badge bg-carmin text-white">{{ kpis.en_cocina }}</span></Link>
        <button v-if="puede('gastronomia','crear')" @click="nueva = { tipo: 'mostrador', cliente: '', direccion: '', telefono: '' }" class="btn-secondary">Mostrador</button>
        <button v-if="puede('gastronomia','crear')" @click="nueva = { tipo: 'delivery', cliente: '', direccion: '', telefono: '' }" class="btn-secondary">Delivery</button>
        <button v-if="puede('gastronomia','editar')" @click="configAbierto = true" class="btn-ghost"><Icono nombre="settings" clase="w-4 h-4" /></button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mesas ocupadas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.ocupadas }} <span class="text-sm text-marca-muted font-semibold">/ {{ kpis.mesas }}</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comandas abiertas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.abiertas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En cocina</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.en_cocina ? 'text-amber-600' : ''">{{ kpis.en_cocina }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ventas hoy</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.ventas_hoy, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cubiertos hoy</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.cubiertos_hoy }}</p><p class="text-[11px] text-marca-muted">{{ kpis.comandas_hoy }} comandas</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Propinas hoy</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.propinas_hoy, 0) }}</p></div>
    </div>

    <!-- Fase 89: el plano del salón (cada mesa en su lugar, se acomoda arrastrando) o la lista de siempre. -->
    <div v-if="mesas.length" class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 text-xs font-semibold" data-e2e="vista-salon">
        <button class="px-3 py-1 rounded-full" :class="vista === 'plano' ? 'bg-carmin text-white' : 'text-marca-muted'" @click="cambiarVista('plano')">Plano</button>
        <button class="px-3 py-1 rounded-full" :class="vista === 'lista' ? 'bg-carmin text-white' : 'text-marca-muted'" @click="cambiarVista('lista')">Lista</button>
        <button class="px-3 py-1 rounded-full" :class="vista === 'calor' ? 'bg-carmin text-white' : 'text-marca-muted'" @click="cambiarVista('calor')" data-e2e="vista-calor">Calor</button>
      </div>
      <div v-if="vista === 'plano' && puede('gastronomia','editar')" class="flex gap-2">
        <button v-if="!acomodando" class="btn-secondary !py-1 text-xs" @click="empezarAcomodo" data-e2e="acomodar">Acomodar mesas</button>
        <template v-else><button class="btn-ghost !py-1 text-xs" @click="acomodando = false">Cancelar</button><button class="btn-primary !py-1 text-xs" :disabled="guardandoPlano" @click="guardarPlano" data-e2e="guardar-plano">Guardar plano</button></template>
      </div>
    </div>
    <!-- Fase 90: vista Calor: el mismo plano, pintado por lo que vendió cada mesa en los últimos 30 días. -->
    <div v-if="vista === 'calor'" class="mb-4" data-e2e="calor">
      <div v-if="!calor" class="card text-sm text-marca-muted">Calculando el mapa de calor…</div>
      <template v-else>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Últimos {{ calor.dias }} días</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(calor.total, 0) }}</p><p class="text-[11px] text-marca-muted">{{ calor.comandas }} comandas de mesa</p></div>
          <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">La que más rinde</p><p class="text-xl font-extrabold">{{ calor.top[0]?.comandas ? 'Mesa ' + calor.top[0].nombre : '—' }}</p><p class="text-[11px] text-marca-muted">{{ calor.top[0]?.comandas ? moneda(calor.top[0].total, 0) + ' · ' + calor.top[0].comandas + (calor.top[0].comandas === 1 ? ' vez' : ' veces') : 'Sin cierres todavía' }}</p></div>
          <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mesas frías</p><p class="text-xl font-extrabold tabular-nums" :class="calor.sin_uso ? 'text-amber-700' : ''">{{ calor.sin_uso }}</p><p class="text-[11px] text-marca-muted">sin una sola comanda</p></div>
          <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mejor franja</p><p class="text-xl font-extrabold">{{ mejorFranja?.comandas ? mejorFranja.label : '—' }}</p><p class="text-[11px] text-marca-muted">{{ mejorFranja?.comandas ? moneda(mejorFranja.total, 0) + ' · ' + mejorFranja.comandas + ' comandas' : '' }}</p></div>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-xs text-marca-muted mb-1"><span>Frío</span><span class="h-2 w-40 rounded-full" style="background: linear-gradient(90deg, #eef2ff, #fde68a, #f97316, #b91c1c)"></span><span>Caliente</span><span class="ml-auto">{{ calor.como }}</span></div>
      </template>
    </div>
    <template v-if="vista === 'plano' || vista === 'calor'">
      <div v-for="(grupo, sector) in porSector" :key="sector" class="mb-5">
        <p class="label">{{ sector }}<span v-if="acomodando" class="text-marca-muted font-normal normal-case"> · arrastrá cada mesa a su lugar</span></p>
        <div class="relative w-full rounded-2xl border border-marca-borde bg-white overflow-hidden select-none touch-none" style="aspect-ratio: 16 / 7; background-image: radial-gradient(#e7e2db 1px, transparent 1px); background-size: 24px 24px" :ref="el => (planos[sector] = el)" :data-plano="sector">
          <button v-for="m in grupo" :key="m.id" :data-mesa="m.id" type="button" class="absolute -translate-x-1/2 -translate-y-1/2 border-2 flex flex-col items-center justify-center shadow-sm transition-colors" :data-calor="vista === 'calor' ? (calorDe(m)?.calor ?? 0) : null" :class="[vista === 'calor' ? 'border-white/70 text-marca-texto' : claseMesa(m), m.forma === 'redonda' ? 'rounded-full' : 'rounded-xl', acomodando ? 'cursor-move ring-2 ring-violeta/30' : '']" :style="vista === 'calor' ? estiloCalor(m) : estiloMesa(m)" @pointerdown="acomodando && arrastrar($event, m, sector)" @click="!acomodando && abrirMesa(m)">
            <span class="text-lg font-black leading-none">{{ m.nombre }}</span>
            <span v-if="vista === 'calor'" class="text-[10px] font-bold tabular-nums leading-tight mt-0.5">{{ calorDe(m)?.comandas ? moneda(calorDe(m).total, 0) + ' · ' + calorDe(m).comandas + '×' : 'sin uso' }}</span>
            <span v-else-if="m.comanda" class="text-[10px] font-bold tabular-nums leading-tight mt-0.5">{{ moneda(m.comanda.total, 0) }} · {{ m.comanda.minutos }}'</span>
            <span v-else class="text-[10px] opacity-60 leading-tight mt-0.5">{{ m.capacidad }}p</span>
            <span v-if="m.comanda?.llamado" class="absolute -top-2 -right-2 text-base animate-pulse" data-llamado>{{ m.comanda.llamado === 'cuenta' ? '🧾' : '🙋' }}</span>
            <span v-else-if="m.comanda?.listos" class="absolute -top-2 -right-2 text-base">🔔</span>
          </button>
        </div>
      </div>
    </template>
    <div v-if="vista === 'calor' && calor" class="grid lg:grid-cols-3 gap-4 mb-6" data-e2e="calor-tablas">
      <div class="card"><h2 class="font-bold mb-2">Las que más rinden</h2><div v-for="m in calor.top.filter(x => x.comandas)" :key="m.id" class="flex justify-between py-1.5 border-t border-marca-borde/60 text-sm"><span>Mesa {{ m.nombre }} <span class="text-xs text-marca-muted">{{ m.sector }} · {{ m.comandas }}× · ticket {{ moneda(m.ticket, 0) }}<span v-if="m.minutos"> · {{ m.minutos }}'</span></span></span><b class="tabular-nums">{{ moneda(m.total, 0) }}</b></div><p v-if="!calor.top.some(x => x.comandas)" class="text-sm text-marca-muted">Todavía no hay comandas cerradas.</p></div>
      <div class="card"><h2 class="font-bold mb-2">Las frías</h2><p class="text-xs text-marca-muted mb-1">Las que menos se sientan: quizá están mal ubicadas, o sobran.</p><div v-for="m in calor.frias" :key="m.id" class="flex justify-between py-1.5 border-t border-marca-borde/60 text-sm"><span>Mesa {{ m.nombre }} <span class="text-xs text-marca-muted">{{ m.sector }} · {{ m.capacidad }}p</span></span><b class="tabular-nums" :class="m.comandas ? '' : 'text-amber-700'">{{ m.comandas ? moneda(m.total, 0) : 'sin uso' }}</b></div></div>
      <div class="card"><h2 class="font-bold mb-2">Por franja horaria</h2><div v-for="f in calor.franjas" :key="f.franja" class="py-1.5 border-t border-marca-borde/60 text-sm"><div class="flex justify-between"><span>{{ f.label }} <span class="text-xs text-marca-muted">{{ f.comandas }} comandas · {{ f.cubiertos }} cub.</span></span><b class="tabular-nums">{{ moneda(f.total, 0) }}</b></div><div class="h-1.5 rounded-full bg-gris-light mt-1"><div class="h-1.5 rounded-full bg-carmin" :style="{ width: (calor.total ? f.total / calor.total * 100 : 0) + '%' }"></div></div></div></div>
    </div>
    <template v-else-if="vista === 'lista'">
    <div v-for="(grupo, sector) in porSector" :key="sector" class="mb-5">
      <p class="label">{{ sector }}</p>
      <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-8 gap-3">
        <button v-for="m in grupo" :key="m.id" @click="abrirMesa(m)" class="rounded-2xl p-3 text-left border-2 transition aspect-square flex flex-col justify-between" :class="claseMesa(m)">
          <div class="flex items-start justify-between"><span class="text-2xl font-black">{{ m.nombre }}</span><span class="text-[10px] font-semibold opacity-70">{{ m.capacidad }}p</span></div>
          <div v-if="m.comanda" class="text-xs leading-tight">
            <p class="font-bold tabular-nums text-sm">{{ moneda(m.comanda.total, 0) }}</p>
            <p class="opacity-80">{{ m.comanda.minutos }} min · {{ m.comanda.cubiertos }} cub.</p>
            <p v-if="m.comanda.llamado" class="font-black animate-pulse" data-llamado>{{ m.comanda.llamado === 'cuenta' ? '🧾 Pide la cuenta' : '🙋 Llama al mozo' }} · {{ m.comanda.llamado_min }}'</p>
            <p v-else-if="m.comanda.estado === 'cuenta'" class="font-bold">Pidió la cuenta</p>
            <p v-else-if="m.comanda.listos" class="font-bold">{{ m.comanda.listos }} listo{{ m.comanda.listos > 1 ? 's' : '' }} 🔔</p>
            <p v-else-if="m.comanda.sin_enviar" class="font-bold">{{ m.comanda.sin_enviar }} sin enviar</p>
          </div>
          <p v-else class="text-xs opacity-60">Libre</p>
        </button>
      </div>
    </div>
    </template>
    <div v-if="!mesas.length" class="card text-center text-marca-muted py-12">Todavía no hay mesas en esta sucursal. Creá las primeras con el engranaje de arriba.</div>

    <div v-if="otras.length" class="mb-5">
      <p class="label">Mostrador y delivery</p>
      <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
        <Link v-for="c in otras" :key="c.id" :href="`/gastronomia/comandas/${c.id}`" class="card hover:border-carmin/40">
          <div class="flex items-center justify-between"><span class="font-bold">{{ c.titulo }}</span><span class="badge" :class="c.tipo === 'delivery' ? 'bg-violeta-light text-violeta' : 'bg-lavanda-light text-violeta'">{{ c.tipo }}</span></div>
          <p class="text-xs text-marca-muted">{{ c.minutos }} min · {{ c.items.length }} ítems<span v-if="c.direccion"> · {{ c.direccion }}</span></p>
          <p class="font-extrabold tabular-nums mt-1">{{ moneda(c.total, 0) }}</p>
        </Link>
      </div>
    </div>

    <Modal :abierto="!!nueva" :titulo="nueva?.tipo === 'delivery' ? 'Nuevo delivery' : 'Pedido de mostrador'" @cerrar="nueva = null">
      <template v-if="nueva">
        <label class="label">Nombre del cliente</label><input v-model="nueva.cliente" class="input" placeholder="Para llamarlo cuando esté listo" />
        <template v-if="nueva.tipo === 'delivery'"><label class="label mt-3">Dirección</label><input v-model="nueva.direccion" class="input" /><label class="label mt-3">Teléfono</label><input v-model="nueva.telefono" class="input" /></template>
      </template>
      <template #pie><button class="btn-secondary" @click="nueva = null">Cancelar</button><button class="btn-primary" @click="router.post('/gastronomia/comandas', nueva)">Abrir</button></template>
    </Modal>

    <Modal :abierto="configAbierto" titulo="Mesas de esta sucursal" @cerrar="configAbierto = false">
      <div v-for="m in mesas" :key="m.id" class="flex items-center gap-2 py-1.5 border-b border-marca-borde/60 text-sm"><span class="font-bold w-10">{{ m.nombre }}</span><span class="text-marca-muted flex-1">{{ m.sector }} · {{ m.capacidad }} personas</span><button @click="Object.assign(mf, { forma: m.forma || 'cuadrada', id: m.id, nombre: m.nombre, sector: m.sector, capacidad: m.capacidad, activa: m.activa })" class="text-xs text-violeta">editar</button><Link v-if="!m.comanda" :href="`/gastronomia/mesas/${m.id}`" method="delete" as="button" preserve-scroll class="text-xs text-carmin">quitar</Link></div>
      <div class="mt-3 p-3 rounded-xl bg-marca-fondo grid grid-cols-4 gap-2 items-end">
        <div><label class="label">{{ mf.id ? 'Editar' : 'Nueva' }} mesa</label><input v-model="mf.nombre" class="input !py-1.5" placeholder="Nº" /></div>
        <div><label class="label">Sector</label><input v-model="mf.sector" class="input !py-1.5" placeholder="Salón" list="sectores" /><datalist id="sectores"><option v-for="s in Object.keys(porSector)" :key="s" :value="s" /></datalist></div>
        <div><label class="label">Personas</label><input v-model.number="mf.capacidad" type="number" min="1" class="input !py-1.5" /></div>
        <div><label class="label">Forma</label><select v-model="mf.forma" class="input !py-1.5"><option value="cuadrada">Cuadrada</option><option value="redonda">Redonda</option><option value="larga">Larga</option></select></div>
        <div class="col-span-4 flex gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!mf.nombre" @click="mf.post(`/gastronomia/mesas${mf.id ? '/' + mf.id : ''}`, { preserveScroll: true, onSuccess: () => mf.reset() })">Guardar</button><button v-if="mf.id" class="btn-ghost !py-1 text-xs" @click="mf.reset()">Cancelar</button></div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ mesas: Array, otras: Array, kpis: Object, listaSucursales: Array, calor: Object })
const { puede } = usePermisos()
const porSector = computed(() => { const g = {}; for (const m of props.mesas) { (g[m.sector] ??= []).push(m) } return g })
const claseMesa = m => !m.activa ? 'opacity-40 border-marca-borde bg-white' : !m.comanda ? 'bg-white border-marca-borde hover:border-emerald-400 text-marca-texto' : m.comanda.estado === 'cuenta' ? 'bg-violeta text-white border-violeta' : m.comanda.listos ? 'bg-amber-100 border-amber-400 text-amber-900' : 'bg-carmin-light border-carmin/60 text-carmin-dark'
function abrirMesa(m) { if (m.comanda) router.visit(`/gastronomia/comandas/${m.comanda.id}`); else if (puede('gastronomia', 'crear')) router.post('/gastronomia/comandas', { mesa_id: m.id, cubiertos: 2 }) }
const nueva = ref(null), configAbierto = ref(false)
const mf = useForm({ id: null, nombre: '', sector: 'Salón', capacidad: 4, activa: true, forma: 'cuadrada' })
let timer
onMounted(() => { timer = setInterval(() => { if (!nueva.value && !configAbierto.value && !acomodando.value) router.reload({ only: ['mesas', 'otras', 'kpis'] }) }, 15000); try { vista.value = localStorage.getItem('bs.salon.vista') || 'plano' } catch {}; if (vista.value === 'calor') router.reload({ only: ['calor'] }) })
// Fase 89: plano del salón. Las mesas sin posición se reparten solas en una grilla; "Acomodar" deja arrastrarlas y guarda el % dentro del sector.
const vista = ref('plano'), acomodando = ref(false), guardandoPlano = ref(false), planos = reactive({}), pos = reactive({})
function cambiarVista(v) { vista.value = v; try { localStorage.setItem('bs.salon.vista', v) } catch {}; if (v === 'calor' && !props.calor) router.reload({ only: ['calor'] }) }
// Fase 90: mapa de calor: de frío (lavanda) a caliente (rojo) según lo que vendió cada mesa respecto de la que más vendió.
const calorDe = m => props.calor?.mesas?.find(x => x.id === m.id)
const mejorFranja = computed(() => props.calor ? [...props.calor.franjas].sort((a, b) => b.total - a.total)[0] : null)
function estiloCalor(m) { const c = calorDe(m)?.calor ?? 0; const paradas = [[0, [238, 242, 255]], [0.33, [253, 230, 138]], [0.66, [249, 115, 22]], [1, [185, 28, 28]]]; let i = 1; while (i < paradas.length - 1 && c > paradas[i][0]) i++; const [a, ca] = paradas[i - 1], [b, cb] = paradas[i]; const t = b === a ? 0 : (c - a) / (b - a); const rgb = ca.map((v, k) => Math.round(v + (cb[k] - v) * t)); return { ...estiloMesa(m), background: `rgb(${rgb.join(',')})`, color: c > 0.5 ? '#fff' : undefined } }
const TAM = { cuadrada: [76, 76], redonda: [76, 76], larga: [124, 64] }
function posicion(m) {
  if (pos[m.id]) return pos[m.id]
  if (m.pos_x !== null && m.pos_x !== undefined) return { x: m.pos_x, y: m.pos_y }
  const grupo = porSector.value[m.sector] ?? []; const i = grupo.indexOf(m); const cols = 7
  return { x: 9 + (i % cols) * 13.5, y: 22 + Math.floor(i / cols) * 36 }
}
function estiloMesa(m) { const p = posicion(m); const [w, h] = TAM[m.forma] ?? TAM.cuadrada; return { left: p.x + '%', top: p.y + '%', width: w + 'px', height: h + 'px' } }
function empezarAcomodo() { for (const m of props.mesas) pos[m.id] = { ...posicion(m) }; acomodando.value = true }
function arrastrar(e, m, sector) {
  const cont = planos[sector]; if (!cont) return
  e.preventDefault(); const r = cont.getBoundingClientRect(); const el = e.currentTarget; el.setPointerCapture?.(e.pointerId)
  const mover = ev => { const x = Math.min(96, Math.max(4, (ev.clientX - r.left) / r.width * 100)); const y = Math.min(92, Math.max(8, (ev.clientY - r.top) / r.height * 100)); pos[m.id] = { x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 } }
  const soltar = () => { el.removeEventListener('pointermove', mover); el.removeEventListener('pointerup', soltar); el.removeEventListener('pointercancel', soltar) }
  el.addEventListener('pointermove', mover); el.addEventListener('pointerup', soltar); el.addEventListener('pointercancel', soltar)
}
function guardarPlano() {
  guardandoPlano.value = true
  router.post('/gastronomia/mesas/plano', { mesas: props.mesas.map(m => ({ id: m.id, pos_x: posicion(m).x, pos_y: posicion(m).y })) }, { preserveScroll: true, onFinish: () => { guardandoPlano.value = false; acomodando.value = false } })
}
onBeforeUnmount(() => clearInterval(timer))
</script>
