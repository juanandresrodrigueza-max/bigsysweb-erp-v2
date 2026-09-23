<template>
  <AppLayout titulo="Seguridad y API">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card">
        <h2 class="font-bold mb-1">Verificación en dos pasos</h2>
        <p class="text-sm text-marca-muted mb-3">Además de la contraseña, al entrar se pide un código de la app del teléfono (Google Authenticator, Authy, Microsoft Authenticator). Es para tu usuario: cada persona activa el suyo.</p>
        <div v-if="dosFactores.activo && !codigosNuevos" class="flex items-center justify-between gap-3 p-3 rounded-xl bg-emerald-50 text-emerald-800 text-sm">
          <span>Activa desde el {{ dosFactores.desde }} · {{ dosFactores.recuperacion }} códigos de recuperación sin usar</span>
          <button class="btn-ghost !px-2 text-xs" @click="desAbierto = true">Desactivar</button>
        </div>
        <div v-else-if="codigosNuevos" class="p-3 rounded-xl bg-amber-50 text-amber-900 text-sm">
          <p class="font-bold mb-1">Guardá estos códigos de recuperación</p>
          <p class="text-xs mb-2">Cada uno sirve una vez, por si perdés el teléfono. No se vuelven a mostrar.</p>
          <div class="grid grid-cols-2 gap-1 font-mono text-xs"><span v-for="c in codigosNuevos" :key="c" class="bg-white rounded px-2 py-1">{{ c }}</span></div>
          <button class="btn-secondary !py-1 text-xs mt-2" @click="copiar(codigosNuevos.join('\n'))">Copiar</button>
        </div>
        <div v-else-if="setup" class="grid sm:grid-cols-2 gap-4 items-start">
          <div class="flex flex-col items-center"><canvas ref="qr" class="rounded-lg border border-marca-borde"></canvas><p class="text-[11px] text-marca-muted mt-1 text-center">Escaneá con la app. O cargá la clave a mano:<br><code class="select-all">{{ setup.secreto }}</code></p></div>
          <form @submit.prevent="conf.post('/configuracion/seguridad/2fa/confirmar', { preserveScroll: true, onSuccess: () => conf.reset() })" class="space-y-2">
            <label class="label">Código que muestra la app</label>
            <input v-model="conf.codigo" class="input text-center text-xl tracking-[.3em] tabular-nums" inputmode="numeric" maxlength="6" autofocus />
            <p v-if="conf.errors.codigo" class="text-carmin text-xs">{{ conf.errors.codigo }}</p>
            <button class="btn-primary w-full" :disabled="conf.processing || conf.codigo.length < 6">Activar</button>
          </form>
        </div>
        <button v-else class="btn-primary" @click="router.post('/configuracion/seguridad/2fa/iniciar', {}, { preserveScroll: true })">Activar verificación en dos pasos</button>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Sesiones abiertas</h2><button class="btn-secondary !py-1 text-xs" @click="sesAbierto = true">Cerrar las otras sesiones</button></div>
        <p class="text-sm text-marca-muted mb-3">Dónde está abierta tu cuenta ahora. Si ves algo raro, cerrá todo y cambiá la contraseña.</p>
        <div v-if="sesionesDb" class="space-y-1">
          <div v-for="s in sesiones" :key="s.id" class="flex items-center justify-between text-sm py-1.5 border-t border-marca-borde/60 first:border-0">
            <div><p class="font-medium">{{ s.agente }} <span v-if="s.actual" class="badge bg-emerald-50 text-emerald-700 ml-1">esta</span></p><p class="text-xs text-marca-muted">{{ s.ip }} · activa {{ s.activa }}</p></div>
            <button v-if="!s.actual" class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/configuracion/seguridad/sesiones/${s.id}`, { preserveScroll: true })">Cerrar</button>
          </div>
        </div>
        <p v-else class="text-xs text-marca-muted">El detalle por dispositivo aparece cuando las sesiones se guardan en la base (SESSION_DRIVER=database).</p>
      </div>

      <div class="card">
        <h2 class="font-bold mb-1">Tokens de API</h2>
        <p class="text-sm text-marca-muted mb-3">Para conectar otros sistemas (tienda, app propia, BI). Se usa como <code>Authorization: Bearer TOKEN</code> contra <code>{{ apiUrl }}</code>. Hereda tus permisos.</p>
        <div v-if="tokenNuevo" class="p-3 rounded-xl bg-amber-50 text-amber-900 text-xs mb-3"><p class="font-bold">Copiá el token ahora, no se vuelve a mostrar:</p><code class="block break-all select-all mt-1 bg-white rounded p-2">{{ tokenNuevo }}</code><button class="btn-secondary !py-1 text-xs mt-2" @click="copiar(tokenNuevo)">Copiar</button></div>
        <form @submit.prevent="tk.post('/configuracion/seguridad/tokens', { preserveScroll: true, onSuccess: () => tk.reset() })" class="flex gap-2 mb-3"><input v-model="tk.nombre" class="input" placeholder="Nombre (ej. Tienda web)" /><button class="btn-primary whitespace-nowrap" :disabled="tk.processing || !tk.nombre">Crear token</button></form>
        <div v-for="t in tokens" :key="t.id" class="flex items-center justify-between text-sm py-1.5 border-t border-marca-borde/60"><div><p class="font-medium">{{ t.nombre }}</p><p class="text-xs text-marca-muted">creado {{ t.creado }} · último uso {{ t.ultimo_uso }}</p></div><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/configuracion/seguridad/tokens/${t.id}`, { preserveScroll: true })">Revocar</button></div>
        <details class="mt-3 text-xs text-marca-muted"><summary class="cursor-pointer font-semibold">Endpoints principales</summary><ul class="mt-1 space-y-0.5 font-mono"><li>GET /api/products · GET /api/products/{id}</li><li>GET /api/contacts · POST /api/contacts</li><li>GET /api/invoices · POST /api/invoices</li><li>GET /api/stock-movements</li><li>GET /api/reports/sales?from=&to=</li></ul></details>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Webhooks</h2><button class="btn-primary !py-1 text-xs" @click="abrirWebhook()">Nuevo</button></div>
        <p class="text-sm text-marca-muted mb-3">Avisamos a una URL tuya cada vez que pasa algo (factura emitida, cobro, stock bajo…). El POST va firmado con HMAC-SHA256 en <code>X-BigSys-Firma</code>.</p>
        <div v-for="w in webhooks" :key="w.id" class="border-t border-marca-borde/60 py-2 text-sm">
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0"><p class="font-medium truncate">{{ w.nombre }} <span class="badge ml-1" :class="w.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ w.activo ? 'activo' : 'pausado' }}</span><span v-if="w.fallos" class="badge bg-red-50 text-carmin ml-1">{{ w.fallos }} fallos</span></p><p class="text-xs text-marca-muted truncate">{{ w.url }} · {{ w.eventos.join(', ') }}</p></div>
            <div class="flex gap-1 shrink-0"><button class="btn-ghost !px-2 text-xs" @click="router.post(`/configuracion/seguridad/webhooks/${w.id}/probar`, {}, { preserveScroll: true })">Probar</button><button class="btn-ghost !px-2 text-xs" @click="abrirWebhook(w)">Editar</button><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/configuracion/seguridad/webhooks/${w.id}`, { preserveScroll: true })">Borrar</button></div>
          </div>
          <details v-if="w.entregas.length" class="text-xs text-marca-muted mt-1"><summary class="cursor-pointer">Últimos envíos</summary><p v-for="e in w.entregas" :key="e.id" class="font-mono">{{ e.fecha }} · {{ e.evento }} · <span :class="e.status && e.status < 400 ? 'text-emerald-700' : 'text-carmin'">{{ e.status ?? 'sin conexión' }}</span> · {{ e.ms }} ms</p></details>
          <p class="text-[11px] text-marca-muted mt-1">Secreto: <code class="select-all">{{ w.secreto }}</code></p>
        </div>
        <p v-if="!webhooks.length" class="text-sm text-marca-muted">Sin webhooks todavía.</p>
      </div>
    </div>

    <Modal :abierto="desAbierto" titulo="Desactivar dos pasos" @cerrar="desAbierto = false">
      <label class="label">Confirmá con tu contraseña</label><input v-model="des.password" type="password" class="input" /><p v-if="des.errors.password" class="text-carmin text-xs mt-1">{{ des.errors.password }}</p>
      <template #pie><button class="btn-secondary" @click="desAbierto = false">Cancelar</button><button class="btn-primary" :disabled="des.processing" @click="des.post('/configuracion/seguridad/2fa/desactivar', { preserveScroll: true, onSuccess: () => { desAbierto = false; des.reset() } })">Desactivar</button></template>
    </Modal>
    <Modal :abierto="sesAbierto" titulo="Cerrar las otras sesiones" @cerrar="sesAbierto = false">
      <p class="text-sm mb-2">Se cierran todas las sesiones de tu usuario menos esta. Confirmá con tu contraseña.</p>
      <input v-model="ses.password" type="password" class="input" /><p v-if="ses.errors.password" class="text-carmin text-xs mt-1">{{ ses.errors.password }}</p>
      <template #pie><button class="btn-secondary" @click="sesAbierto = false">Cancelar</button><button class="btn-primary" :disabled="ses.processing" @click="ses.post('/configuracion/seguridad/sesiones/cerrar', { preserveScroll: true, onSuccess: () => { sesAbierto = false; ses.reset() } })">Cerrar sesiones</button></template>
    </Modal>
    <Modal :abierto="whAbierto" :titulo="wh.id ? 'Editar webhook' : 'Nuevo webhook'" @cerrar="whAbierto = false">
      <div class="space-y-3">
        <div><label class="label">Nombre</label><input v-model="wh.nombre" class="input" placeholder="Ej. Tienda web" /></div>
        <div><label class="label">URL</label><input v-model="wh.url" class="input" placeholder="https://…" /><p v-if="wh.errors.url" class="text-carmin text-xs mt-1">{{ wh.errors.url }}</p></div>
        <div><label class="label">Eventos</label><div class="grid sm:grid-cols-2 gap-1 text-sm"><label v-for="(lbl, k) in eventos" :key="k" class="flex items-center gap-2"><input type="checkbox" :value="k" v-model="wh.eventos" class="accent-carmin" /> {{ lbl }}</label></div><p v-if="wh.errors.eventos" class="text-carmin text-xs mt-1">{{ wh.errors.eventos }}</p></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="wh.activo" type="checkbox" class="accent-carmin" /> Activo</label>
      </div>
      <template #pie><button class="btn-secondary" @click="whAbierto = false">Cancelar</button><button class="btn-primary" :disabled="wh.processing" @click="wh.post(`/configuracion/seguridad/webhooks${wh.id ? '/' + wh.id : ''}`, { preserveScroll: true, onSuccess: () => (whAbierto = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, watch, nextTick, onMounted } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import QRCode from 'qrcode'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
const props = defineProps({ dosFactores: Object, setup: Object, codigosNuevos: Array, sesiones: Array, sesionesDb: Boolean, tokens: Array, tokenNuevo: String, webhooks: Array, eventos: Object, apiUrl: String })
const qr = ref(null)
const dibujarQr = () => { if (props.setup && qr.value) QRCode.toCanvas(qr.value, props.setup.uri, { width: 180, margin: 1 }).catch(() => {}) }
onMounted(dibujarQr); watch(() => props.setup, () => nextTick(dibujarQr))
const conf = useForm({ codigo: '' })
const desAbierto = ref(false); const des = useForm({ password: '' })
const sesAbierto = ref(false); const ses = useForm({ password: '' })
const tk = useForm({ nombre: '' })
const whAbierto = ref(false)
const wh = useForm({ id: null, nombre: '', url: '', eventos: [], activo: true })
function abrirWebhook(w = null) { wh.clearErrors(); Object.assign(wh, w ? { id: w.id, nombre: w.nombre, url: w.url, eventos: [...w.eventos], activo: w.activo } : { id: null, nombre: '', url: '', eventos: ['comprobante.emitido'], activo: true }); whAbierto.value = true }
function copiar(t) { navigator.clipboard?.writeText(t).catch(() => {}) }
</script>
