<template>
  <AppLayout titulo="Puntos de venta y ARCA">
    <div class="mb-5"><h1 class="page-title">Puntos de venta y ARCA</h1><p class="page-subtitle">Los puntos de venta habilitados en ARCA y el certificado para facturar electrónicamente.</p></div>

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Puntos de venta</h2><button @click="abrir()" class="btn-primary !py-1.5 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Nuevo</button></div>
        <p class="text-sm text-marca-muted mb-3">Cada punto de venta numera sus comprobantes. Tienen que estar dados de alta en ARCA con el mismo número.</p>
        <table class="table">
          <thead><tr><th>N°</th><th>Sucursal</th><th>Modo</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <tr v-for="p in puntos" :key="p.id">
              <td class="font-extrabold tabular-nums">{{ String(p.numero).padStart(4, '0') }}</td><td>{{ p.sucursal ?? 'General' }}</td><td class="capitalize text-marca-muted">{{ p.modo }}</td>
              <td><span class="badge" :class="p.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ p.activo ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="text-right"><button @click="abrir(p)" class="btn-ghost !px-2 text-xs" title="Editar" aria-label="Editar"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
            </tr>
            <tr v-if="!puntos.length"><td colspan="5" class="!py-0"><EstadoVacio texto="Sin puntos de venta. Creá el 0001 para empezar." compacto /></td></tr>
          </tbody>
        </table>
      </div>

      <div class="card" data-e2e="arca">
        <h2 class="font-bold mb-1">Factura electrónica ARCA</h2>
        <div class="flex items-start gap-2 mb-3" data-e2e="arca-estado">
          <span class="mt-1.5 w-2.5 h-2.5 rounded-full shrink-0" :class="afip.configurado ? 'bg-emerald-500' : 'bg-amber-500'"></span>
          <span class="text-sm">
            <template v-if="afip.modo === 'bigsys'"><b>Factura a través de BigSys</b> · CUIT {{ arca.cuit }} · {{ arca.produccion ? 'Producción' : 'Homologación (prueba)' }}<span v-if="arca.verificada" class="text-marca-muted"> · verificada el {{ arca.verificada }}</span></template>
            <template v-else-if="afip.modo === 'propio'"><b>Certificado propio</b> · CUIT {{ afip.cuit }} · {{ afip.produccion ? 'Producción' : 'Homologación' }}</template>
            <template v-else>Sin configurar: las facturas se emiten simuladas (sin CAE).</template>
          </span>
        </div>

        <!-- Fase 121: como en el CRM. La empresa no carga certificado: le delega el servicio a BigSys en ARCA. -->
        <div v-if="arca.plataforma && afip.modo !== 'propio'" class="rounded-2xl border border-violeta/30 bg-violeta-light/30 p-3 mb-3" data-e2e="arca-guia">
          <p class="font-bold text-sm">{{ arca.bigsys ? 'Facturás a través de BigSys' : 'Facturá sin certificado: BigSys factura por vos' }}</p>
          <p class="text-xs text-marca-muted mb-2">No tenés que generar, subir ni descargar ningún certificado. Hacés dos trámites en ARCA (una sola vez), los verificás desde acá y listo.</p>
          <ol class="space-y-2.5 text-sm">
            <li class="flex gap-2"><span :class="paso(arca.cuit_ok ? 'ok' : '')">{{ arca.cuit_ok ? '✓' : '1' }}</span><div><b>Datos fiscales de la empresa.</b> <span v-if="arca.cuit_ok" class="text-marca-muted">CUIT {{ arca.cuit }}.</span><span v-else>Falta el CUIT: cargalo en <Link href="/configuracion/empresa" class="underline">Configuración → Empresa</Link>.</span></div></li>
            <li class="flex gap-2"><span :class="paso(verif?.alta_punto_venta)">{{ verif?.alta_punto_venta === 'ok' ? '✓' : '2' }}</span><div><b>Dá de alta el punto de venta para Web Services.</b> En ARCA → «Administración de puntos de venta y domicilios» → Agregar, y en «Sistema» elegí <b>«RECE para aplicativo y web services»</b> si sos responsable inscripto, o <b>«Factura Electrónica - Monotributo - Web Services»</b> si sos monotributista. El de «Comprobantes en línea» (el de facturar a mano en la web de ARCA) no sirve acá.
              <p v-if="verif?.alta_punto_venta === 'falta'" class="text-carmin text-xs mt-0.5">ARCA te reconoce, pero no tenés ningún punto de venta dado de alta para web services todavía.</p></div></li>
            <li class="flex gap-2"><span :class="paso(verif?.delegacion)">{{ verif?.delegacion === 'ok' ? '✓' : '3' }}</span><div><b>Autorizá a {{ arca.representante?.razon ?? 'BigSys' }} a facturar en tu nombre.</b> Entrá a ARCA con clave fiscal (nivel 3) → <b>«Administrador de Relaciones de Clave Fiscal»</b> → <b>Nueva relación</b> → Buscar → ARCA → WebServices → <b>«Facturación Electrónica»</b> → en Representante poné el CUIT
              <button v-if="arca.representante" type="button" class="font-mono font-bold underline decoration-dotted" @click="copiar(arca.representante.cuit)" data-e2e="arca-cuit-bigsys">{{ arca.representante.cuit }}</button><span v-if="copiado" class="text-emerald-700 text-xs"> · copiado</span> → Confirmar.
              <p v-if="verif?.delegacion === 'falta'" class="text-carmin text-xs mt-0.5">ARCA dice que todavía no está hecha (o se hizo con otro CUIT). Puede tardar unos minutos en verse después de hacerla.</p></div></li>
            <li class="flex gap-2"><span :class="paso(arca.bigsys ? 'ok' : '')">{{ arca.bigsys ? '✓' : '4' }}</span><div class="flex-1"><b>Verificá y activá.</b> Le pregunta a ARCA si los dos trámites quedaron bien. No emite ni reserva nada.
              <!-- Fase 121.1: autorización expresa, con constancia de quién, cuándo y con qué texto -->
              <div v-if="hayParaActivar" class="mt-2 rounded-xl border border-marca-borde bg-white p-2.5 text-xs" data-e2e="arca-autorizacion">
                <p class="text-marca-muted leading-relaxed">{{ arca.autorizacion?.texto }} <a href="/terminos#facturacion" target="_blank" class="underline">Ver términos</a></p>
                <label v-if="arca.puede_aceptar" class="flex items-start gap-2 mt-2 font-semibold"><input v-model="acepto" type="checkbox" class="mt-0.5 accent-carmin" data-e2e="arca-acepto" /> Leí y acepto esta autorización en nombre de la empresa, y declaro tener facultades para hacerlo.</label>
                <p v-else class="mt-2 font-semibold text-amber-700">La autorización la acepta el dueño de la cuenta: pedile que entre y la active.</p>
                <p v-if="errorAcepto" class="text-carmin mt-1">{{ errorAcepto }}</p>
              </div>
              <div class="flex flex-wrap gap-2 mt-1.5">
                <button type="button" class="btn-secondary !py-1 text-xs" :disabled="verificando || !arca.cuit_ok" @click="verificar()" data-e2e="arca-verificar">{{ verificando ? 'Consultando a ARCA…' : 'Verificar en ARCA' }}</button>
                <button v-if="!arca.bigsys" type="button" class="btn-primary !py-1 text-xs" :disabled="verif?.delegacion !== 'ok' || !acepto" @click="activar(true)" data-e2e="arca-activar">Activar la facturación por BigSys</button>
                <button v-else type="button" class="btn-ghost !py-1 text-xs text-carmin" @click="activar(false)">Apagar</button>
              </div>
              <p v-if="arca.bigsys && arca.autorizacion?.ultima && !arca.autorizacion.ultima.revocada" class="text-[11px] text-marca-muted mt-1" data-e2e="arca-constancia">Autorizado por {{ arca.autorizacion.ultima.usuario }} el {{ arca.autorizacion.ultima.fecha }} (texto versión {{ arca.autorizacion.ultima.version }}).</p>
              </div></li>
          </ol>
          <div v-if="arca.revocar" class="mt-3 rounded-xl p-2.5 text-xs bg-amber-50 text-amber-800" data-e2e="arca-revocar">
            <p class="font-bold mb-1">Para cerrar del todo, revocá también la autorización en ARCA:</p>
            <ol class="list-decimal pl-4 space-y-0.5"><li v-for="p in arca.revocar" :key="p">{{ p }}</li></ol>
          </div>
          <div v-if="verif" class="mt-3 rounded-xl p-2.5 text-xs" :class="verif.delegacion === 'ok' ? 'bg-emerald-50 text-emerald-800' : verif.delegacion === 'falta' ? 'bg-amber-50 text-amber-800' : 'bg-carmin-light text-carmin-dark'" data-e2e="arca-verificacion">
            <template v-if="verif.delegacion === 'ok'">
              ✓ ARCA confirma la autorización a BigSys.<span v-if="verif.puntos.length"> Puntos de venta para web services: <b>{{ verif.puntos.map(n => String(n).padStart(4, '0')).join(', ') }}</b>.</span>
              <p v-if="verif.faltan_en_arca?.length" class="mt-1 text-carmin">Ojo: acá tenés como electrónico el {{ verif.faltan_en_arca.map(n => String(n).padStart(4, '0')).join(', ') }}, que en ARCA no está para web services: las facturas de ese punto van a rebotar.</p>
              <p v-if="verif.faltan_aca?.length" class="mt-1">En ARCA tenés {{ verif.faltan_aca.map(n => String(n).padStart(4, '0')).join(', ') }} y acá no. <button type="button" class="underline font-semibold" @click="traer(verif.faltan_aca)" data-e2e="arca-traer">Agregarlos</button></p>
            </template>
            <template v-else-if="verif.delegacion === 'falta'">Falta el paso 3: la autorización a BigSys en el Administrador de Relaciones de ARCA.</template>
            <template v-else>No se pudo consultar a ARCA: {{ verif.mensaje }}</template>
          </div>
          <div v-if="arca.sucursales.length" class="mt-3 pt-2 border-t border-violeta/20 text-xs">
            <p class="font-semibold mb-1">Sucursales con CUIT propio</p>
            <p class="text-marca-muted mb-1">Cada CUIT hace su propia autorización en ARCA (paso 3, entrando con la clave fiscal de ese CUIT).</p>
            <div v-for="l in arca.sucursales" :key="l.id" class="flex flex-wrap items-center justify-between gap-2 py-1">
              <span><b>{{ l.nombre }}</b> · CUIT {{ l.cuit }} · {{ l.propio ? 'certificado propio' : (l.bigsys ? 'a través de BigSys' : 'sin configurar') }}</span>
              <span v-if="!l.propio" class="flex gap-1"><button type="button" class="btn-ghost !py-0.5 text-xs" @click="verificar(l.id)">Verificar</button><button type="button" class="btn-ghost !py-0.5 text-xs" :disabled="!l.bigsys && !acepto" :title="!l.bigsys && !acepto ? 'Primero aceptá la autorización (paso 4)' : ''" @click="activar(!l.bigsys, l.id)">{{ l.bigsys ? 'Apagar' : 'Activar' }}</button></span>
            </div>
          </div>
        </div>
        <p v-else-if="!arca.plataforma && afip.modo !== 'propio'" class="text-xs text-marca-muted mb-3">La facturación a través de BigSys (sin certificado) todavía no está habilitada en esta instalación. Mientras tanto podés cargar tu propio certificado.</p>

        <div v-if="afip.configurado" class="flex flex-wrap items-center gap-2 mb-3">
          <button class="btn-violeta !py-1 text-xs" :disabled="probando" @click="probar" data-e2e="arca-probar">{{ probando ? 'Probando…' : 'Probar conexión con ARCA' }}</button>
          <span v-if="afip.pendientes" class="badge bg-carmin-light text-carmin">{{ afip.pendientes }} comprobante(s) pendiente(s) de CAE</span>
        </div>
        <div v-if="prueba" class="rounded-xl border p-3 mb-3 text-sm" :class="prueba.ok ? 'border-emerald-200 bg-emerald-50' : 'border-carmin/30 bg-carmin-light'" data-e2e="arca-prueba">
          <p class="font-bold mb-1">Prueba en {{ prueba.modo }}: {{ prueba.ok ? 'todo OK' : 'con problemas' }}</p>
          <div v-for="p in prueba.pasos" :key="p.nombre" class="flex items-start gap-2 py-0.5"><span class="mt-1 w-2 h-2 rounded-full shrink-0" :class="p.ok ? 'bg-emerald-500' : 'bg-carmin'"></span><span><b>{{ p.nombre }}</b> · {{ p.detalle }}</span></div>
        </div>

        <details class="text-sm" :open="afip.modo === 'propio' || !arca.plataforma">
          <summary class="cursor-pointer font-semibold text-violeta">Tengo mi propio certificado (avanzado)</summary>
          <p v-if="arca.plataforma" class="text-xs text-marca-muted mt-1">Sólo si ya facturás por web services con otro sistema y querés usar tu certificado. Si cargás uno, se usa ese en lugar de BigSys. La factura de exportación y la consulta de padrón por ahora necesitan certificado propio.</p>
          <ol class="text-sm text-marca-muted space-y-1 my-3 list-decimal pl-5">
            <li>Generá el certificado en ARCA (Administración de certificados digitales) y asociale «Facturación Electrónica» en el Administrador de Relaciones.</li>
            <li>Subí acá el certificado (.crt) y la clave privada (.key). Quedan cifrados.</li>
            <li>Verificá el CUIT y el modo (homologación para probar, producción para facturar de verdad) en la pestaña Empresa.</li>
            <li>Dale de alta el punto de venta en ARCA como «RECE para aplicativo y web services» (o «Factura Electrónica - Monotributo - Web Services» si sos monotributista) con el mismo número que acá.</li>
            <li>Probá la conexión y emití una factura de prueba en homologación antes de pasar a producción.</li>
          </ol>
          <form @submit.prevent="cert.post('/configuracion/afip/certificados', { forceFormData: true, preserveScroll: true })" class="grid sm:grid-cols-2 gap-3">
            <div><label class="label">Certificado (.crt / .pem)</label><input type="file" @change="cert.cert = $event.target.files[0]" class="input !py-1.5 text-xs" /><p v-if="cert.errors.cert" class="text-carmin text-xs mt-1">{{ cert.errors.cert }}</p></div>
            <div><label class="label">Clave privada (.key)</label><input type="file" @change="cert.key = $event.target.files[0]" class="input !py-1.5 text-xs" /><p v-if="cert.errors.key" class="text-carmin text-xs mt-1">{{ cert.errors.key }}</p></div>
            <div class="sm:col-span-2 flex justify-end"><button class="btn-primary" :disabled="cert.processing || !cert.cert || !cert.key">Cargar certificados</button></div>
          </form>
        </details>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar punto de venta' : 'Nuevo punto de venta'" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Número</label><input v-model.number="form.numero" type="number" min="1" max="9999" class="input" /><p v-if="form.errors.numero" class="text-carmin text-xs mt-1">{{ form.errors.numero }}</p></div>
        <div><label class="label">Sucursal</label><select v-model="form.business_location_id" class="input"><option :value="null">General</option><option v-for="s in sucursales" :key="s.id" :value="s.id">{{ s.name }}{{ s.cuit ? ' · CUIT propio ' + s.cuit : '' }}</option></select></div>
        <div><label class="label">Modo</label><select v-model="form.modo" class="input"><option value="electronico">Electrónico (ARCA)</option><option value="manual">Manual / talonario</option></select></div>
        <label class="flex items-center gap-2 text-sm mt-6"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Activo</label>
      </div>
      <template #pie>
        <button class="btn-secondary" @click="modal = false">Cancelar</button>
        <button class="btn-primary" :disabled="form.processing" @click="form.post(`/configuracion/puntos-venta${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'

const props = defineProps({ puntos: Array, sucursales: Array, afip: Object, prueba: Object, arca: { type: Object, default: () => ({ plataforma: false, sucursales: [] }) } })
// Fase 121: verificar los trámites en ARCA, activar la facturación por BigSys y traer los puntos de venta.
const verif = ref(null), verificando = ref(false), copiado = ref(false)
async function verificar(locationId = null) {
  verificando.value = true
  try { const { data } = await window.axios.post('/configuracion/afip/verificar', locationId ? { location_id: locationId } : {}); verif.value = data }
  catch (e) { verif.value = { delegacion: 'error', mensaje: e.response?.data?.message ?? 'No se pudo consultar a ARCA.', puntos: [] } }
  finally { verificando.value = false }
}
function paso(e) { return 'w-6 h-6 shrink-0 rounded-full grid place-items-center text-xs font-bold ' + (e === 'ok' ? 'bg-emerald-500 text-white' : e === 'falta' ? 'bg-amber-400 text-white' : 'bg-gris-light text-marca-muted') }
const acepto = ref(false), errorAcepto = ref(null)
const hayParaActivar = computed(() => !props.arca.bigsys || (props.arca.sucursales ?? []).some(l => !l.bigsys && !l.propio))
function activar(activo, locationId = null) {
  if (!activo && !confirm('¿Apagar la facturación a través de BigSys? Las facturas van a salir simuladas hasta que la vuelvas a activar.')) return
  errorAcepto.value = null
  router.post('/configuracion/afip/bigsys', { activo, location_id: locationId, acepto: activo ? acepto.value : null, version: props.arca.autorizacion?.version }, { preserveScroll: true, onError: (e) => (errorAcepto.value = e.acepto ?? null), onSuccess: () => (acepto.value = false) })
}
function traer(numeros) { router.post('/configuracion/afip/traer-puntos', { numeros }, { preserveScroll: true, onSuccess: () => verificar() }) }
async function copiar(t) { try { await navigator.clipboard.writeText(t.replace(/\D/g, '')); copiado.value = true; setTimeout(() => (copiado.value = false), 2000) } catch (e) { window.prompt('Copiá el CUIT', t) } }
const probando = ref(false)
function probar() { probando.value = true; router.post('/configuracion/afip/probar', {}, { preserveScroll: true, onFinish: () => (probando.value = false) }) }
const modal = ref(false)
const form = useForm({ id: null, numero: (Math.max(0, ...props.puntos.map(p => p.numero)) + 1), business_location_id: null, modo: 'electronico', activo: true })
const cert = useForm({ cert: null, key: null })
function abrir(p) { form.clearErrors(); Object.assign(form, p ? { ...p } : { id: null, numero: Math.max(0, ...props.puntos.map(x => x.numero)) + 1, business_location_id: null, modo: 'electronico', activo: true }); modal.value = true }
</script>
