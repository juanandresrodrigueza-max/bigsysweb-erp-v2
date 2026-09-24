<template>
  <AppLayout titulo="Catálogos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted">← Stock</Link>
        <h1 class="page-title">Catálogos</h1>
        <p class="text-sm text-marca-muted max-w-2xl">Un catálogo con tus artículos, tu logo y tus colores para mandar a los clientes. Cada uno se arma con una lista de precios; si lo mandás a un cliente, ve sus propios precios (su lista, sus pactados y sus descuentos por rubro).</p>
      </div>
      <button v-if="puede('stock', 'editar')" class="btn-primary" @click="nuevo()" data-nuevo-catalogo><Icono nombre="plus" clase="w-4 h-4" /> Nuevo catálogo</button>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
      <article v-for="c in catalogos" :key="c.id" class="card space-y-3" :class="!c.activo ? 'opacity-60' : ''" data-catalogo>
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="font-bold">{{ c.nombre }}</h2>
            <p class="text-xs text-marca-muted">Lista {{ c.lista }} · {{ c.rubros?.length ? c.rubros.length + ' rubros' : 'todos los rubros' }} · {{ c.iva_incluido ? 'con IVA' : 'sin IVA' }}<span v-if="c.solo_con_stock"> · solo con stock</span></p>
          </div>
          <span class="badge" :class="c.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-marca-fondo text-marca-muted'">{{ c.activo ? 'Activo' : 'Pausado' }}</span>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center">
          <div class="rounded-xl bg-marca-fondo py-2"><p class="text-lg font-extrabold tabular-nums">{{ c.vistas }}</p><p class="text-[11px] text-marca-muted">vistas<span v-if="c.visto"> · última {{ c.visto }}</span></p></div>
          <div class="rounded-xl bg-marca-fondo py-2"><p class="text-lg font-extrabold tabular-nums">{{ c.enviados }}</p><p class="text-[11px] text-marca-muted">envíos</p></div>
          <div class="rounded-xl bg-marca-fondo py-2"><p class="text-lg font-extrabold tabular-nums">{{ c.clientes_lista }}</p><p class="text-[11px] text-marca-muted">clientes en lista {{ c.lista }}</p></div>
        </div>
        <div class="flex items-center gap-2 rounded-xl border border-marca-borde px-3 py-2 text-xs"><code class="truncate flex-1" data-link>{{ c.url }}</code><button type="button" class="text-violeta font-semibold" @click="copiar(c)">{{ copiado === c.id ? 'Copiado' : 'Copiar' }}</button></div>
        <div class="flex flex-wrap gap-2">
          <a :href="c.url" target="_blank" class="btn-secondary !py-1.5 text-xs">Abrir</a>
          <a :href="c.url + '/pdf'" class="btn-secondary !py-1.5 text-xs">Bajar PDF</a>
          <button type="button" class="btn-primary !py-1.5 text-xs" @click="abrirEnvio(c)" data-mandar>Mandar a un cliente</button>
          <button type="button" class="btn-secondary !py-1.5 text-xs" @click="masivo = c">Mandar a toda la lista {{ c.lista }}</button>
          <span class="flex-1"></span>
          <button v-if="puede('stock', 'editar')" type="button" class="btn-ghost !py-1.5 text-xs" @click="editar(c)">Editar</button>
          <button v-if="puede('stock', 'editar')" type="button" class="btn-ghost !py-1.5 text-xs" @click="router.post(`/stock/catalogos/${c.id}/renovar`, {}, { preserveScroll: true })" title="El link anterior deja de abrir">Link nuevo</button>
        </div>
      </article>
      <div v-if="!catalogos.length" class="card md:col-span-2 text-center py-12 text-marca-muted">Todavía no hay catálogos. Creá el primero con la lista mayorista o la de mostrador.</div>
    </div>

    <!-- Alta / edición -->
    <Modal :abierto="editando" :titulo="form.id ? 'Editar catálogo' : 'Nuevo catálogo'" ancho="max-w-xl" @cerrar="editando = false">
      <form @submit.prevent="guardar" class="space-y-3 text-sm" id="form-catalogo">
        <div><label class="label">Nombre</label><input v-model="form.nombre" class="input" placeholder="Lista mayorista septiembre" data-nombre /><p v-if="form.errors.nombre" class="text-xs text-carmin">{{ form.errors.nombre }}</p></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Lista de precios</label><select v-model.number="form.lista" class="input" data-lista><option v-for="n in 6" :key="n" :value="n">Lista {{ n }}{{ porLista[n] ? ` · ${porLista[n]} clientes` : '' }}</option></select></div>
          <div><label class="label">Rubros</label>
            <details class="input !p-0"><summary class="px-3 py-2 cursor-pointer">{{ form.rubros.length ? form.rubros.length + ' elegidos' : 'Todos' }}</summary>
              <div class="max-h-48 overflow-y-auto px-3 pb-2 space-y-1"><label v-for="r in rubros" :key="r.id" class="flex items-center gap-2"><input v-model="form.rubros" type="checkbox" :value="r.id" class="accent-carmin" /> {{ r.nombre }}</label></div>
            </details>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-x-3 gap-y-1.5">
          <label class="flex items-center gap-2"><input v-model="form.iva_incluido" type="checkbox" class="accent-carmin" /> Precios con IVA incluido</label>
          <label class="flex items-center gap-2"><input v-model="form.mostrar_fotos" type="checkbox" class="accent-carmin" /> Fotos</label>
          <label class="flex items-center gap-2"><input v-model="form.mostrar_codigo" type="checkbox" class="accent-carmin" /> Código del artículo</label>
          <label class="flex items-center gap-2"><input v-model="form.mostrar_stock" type="checkbox" class="accent-carmin" /> Mostrar stock</label>
          <label class="flex items-center gap-2"><input v-model="form.solo_con_stock" type="checkbox" class="accent-carmin" /> Solo artículos con stock</label>
          <label class="flex items-center gap-2"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Activo (el link abre)</label>
        </div>
        <div><label class="label">Nota de portada (opcional)</label><textarea v-model="form.nota" rows="2" class="input" placeholder="Precios válidos hasta el 30/09. Pedido mínimo $ 50.000. Envíos sin cargo en Córdoba capital."></textarea></div>
        <p class="text-xs text-marca-muted">Sale con el logo y los colores de Configuración → Empresa → Diseño de comprobantes.</p>
      </form>
      <template #pie>
        <button v-if="form.id" type="button" class="btn-ghost text-carmin mr-auto" @click="borrarConfirmar = true">Eliminar</button>
        <button type="button" class="btn-secondary" @click="editando = false">Cancelar</button>
        <button type="submit" form="form-catalogo" class="btn-primary" :disabled="form.processing" data-guardar-catalogo>Guardar</button>
      </template>
      <div v-if="borrarConfirmar" class="mt-3 rounded-xl border border-carmin/40 bg-carmin-light p-3 text-sm">¿Eliminar "{{ form.nombre }}"? El link deja de funcionar para todos los que lo tengan.
        <div class="flex gap-2 mt-2"><button type="button" class="btn-primary !py-1 text-xs" @click="router.delete(`/stock/catalogos/${form.id}`, { onSuccess: () => { editando = false; borrarConfirmar = false } })">Sí, eliminar</button><button type="button" class="btn-ghost !py-1 text-xs" @click="borrarConfirmar = false">No</button></div>
      </div>
    </Modal>

    <!-- Mandar a un cliente -->
    <Modal :abierto="!!envio" :titulo="`Mandar ${envio?.nombre ?? ''}`" @cerrar="envio = null">
      <div class="space-y-3 text-sm">
        <div><label class="label">Cliente</label><BuscadorSelect v-model="ef.contact_id" :opciones="opcionesClientes" url="/buscar/contactos/cliente" @cargados="f => sumarClientes(f)" placeholder="Buscar cliente…" @elegido="elegirCliente" data-cliente-catalogo /></div>
        <p v-if="clienteElegido" class="text-xs text-marca-muted">Va a ver sus precios: lista {{ clienteElegido.lista_precios ?? 1 }}<span v-if="clienteElegido.descuento"> con {{ clienteElegido.descuento }}% de descuento</span>, más sus precios pactados.</p>
        <div class="flex gap-2"><label v-for="(l, k) in { whatsapp: 'WhatsApp', mail: 'Mail con PDF' }" :key="k" class="flex-1 flex items-center gap-2 rounded-xl border px-3 py-2 cursor-pointer" :class="ef.canal === k ? 'border-carmin bg-carmin-light/40' : 'border-marca-borde'"><input v-model="ef.canal" type="radio" :value="k" class="accent-carmin" @change="completarDestino" /> {{ l }}</label></div>
        <div><label class="label">{{ ef.canal === 'mail' ? 'Email' : 'Teléfono' }}</label><input v-model="ef.destino" class="input" :placeholder="ef.canal === 'mail' ? 'cliente@mail.com' : '351 555 1234'" /></div>
        <p class="text-xs text-marca-muted">Se manda el link del catálogo con los precios del cliente{{ ef.canal === 'mail' ? ' y el PDF adjunto' : '' }}.</p>
      </div>
      <template #pie><button type="button" class="btn-secondary" @click="envio = null">Cancelar</button><button type="button" class="btn-primary" :disabled="!ef.contact_id || ef.processing" @click="mandar" data-confirmar-envio>Mandar</button></template>
    </Modal>

    <!-- Mandar a toda la lista -->
    <Modal :abierto="!!masivo" :titulo="`Mandar a los clientes de la lista ${masivo?.lista}`" @cerrar="masivo = null">
      <p class="text-sm">Se manda "{{ masivo?.nombre }}" a los {{ masivo?.clientes_lista }} clientes activos de la lista {{ masivo?.lista }}, cada uno con sus precios. Los que no tienen email o teléfono se saltean.</p>
      <p class="text-xs text-marca-muted mt-2">Por WhatsApp a muchos clientes a la vez hace falta la API de WhatsApp. Sin API, mandalo cliente por cliente.</p>
      <template #pie>
        <button type="button" class="btn-secondary" @click="masivo = null">Cancelar</button>
        <button type="button" class="btn-secondary" @click="router.post(`/stock/catalogos/${masivo.id}/enviar-lista`, { canal: 'whatsapp' }, { preserveScroll: true, onFinish: () => masivo = null })">Por WhatsApp</button>
        <button type="button" class="btn-primary" @click="router.post(`/stock/catalogos/${masivo.id}/enviar-lista`, { canal: 'mail' }, { preserveScroll: true, onFinish: () => masivo = null })">Por mail</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'

const props = defineProps({ catalogos: Array, rubros: Array, porLista: { type: Object, default: () => ({}) } })
const page = usePage()
const puede = (m, a) => { const p = page.props.auth?.permisos ?? {}; return !!(p['*'] || p[m]?.includes(a)) }
const vacio = { id: null, nombre: '', lista: 1, rubros: [], iva_incluido: true, mostrar_stock: false, solo_con_stock: false, mostrar_fotos: true, mostrar_codigo: true, nota: '', activo: true }
const form = useForm({ ...vacio })
const editando = ref(false), borrarConfirmar = ref(false)
function nuevo() { form.defaults({ ...vacio }); form.reset(); form.clearErrors(); editando.value = true }
function editar(c) { Object.assign(form, { ...vacio, ...c, rubros: c.rubros ?? [], nota: c.nota ?? '' }); editando.value = true }
function guardar() { form.post(form.id ? `/stock/catalogos/${form.id}` : '/stock/catalogos', { preserveScroll: true, onSuccess: () => { editando.value = false } }) }
const copiado = ref(null)
async function copiar(c) { try { await navigator.clipboard.writeText(c.url); copiado.value = c.id; setTimeout(() => copiado.value = null, 1500) } catch (e) {} }

const envio = ref(null), masivo = ref(null)
const clientes = ref([])
const opcionesClientes = computed(() => clientes.value.map(c => ({ id: c.id, label: c.name, sub: c.cuit ?? c.condicion_iva, extra: c.lista_precios ? `Lista ${c.lista_precios}` : null })))
function sumarClientes(f) { const ids = new Set(clientes.value.map(c => c.id)); clientes.value.push(...f.filter(c => !ids.has(c.id))) }
const clienteElegido = computed(() => clientes.value.find(c => c.id === ef.contact_id))
const ef = useForm({ contact_id: null, canal: 'whatsapp', destino: '' })
function abrirEnvio(c) { ef.reset(); envio.value = c }
function completarDestino() { const c = clienteElegido.value; ef.destino = c ? (ef.canal === 'mail' ? (c.email ?? '') : (c.mobile || c.phone || '')) : '' }
function elegirCliente() { completarDestino() }
function mandar() {
  ef.post(`/stock/catalogos/${envio.value.id}/enviar`, { preserveScroll: true, onSuccess: () => { const abrir = page.props.flash?.abrir; if (abrir) { window.open(abrir, '_blank'); const id = page.props.flash?.envio_id; if (id) window.axios.post(`/envios/${id}/marcar`).catch(() => {}) } envio.value = null } })
}
</script>
