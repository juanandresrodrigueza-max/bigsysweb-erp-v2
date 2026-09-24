<template>
  <Modal :abierto="abierto" :titulo="form.id ? 'Editar cliente' : 'Nuevo cliente'" ancho="max-w-2xl" @cerrar="$emit('cerrar')">
    <form @submit.prevent="guardar" class="grid sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2"><label class="label">Nombre / Razón social</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
      <div><label class="label">Condición IVA</label><select v-model="form.condicion_iva" class="input"><option v-for="c in condicionesIva" :key="c">{{ c }}</option></select></div>
      <div><label class="label">CUIT / CUIL</label><div class="flex gap-1"><input v-model="form.cuit" class="input" placeholder="30-12345678-9" /><button type="button" class="btn-secondary !px-2 text-xs whitespace-nowrap" :disabled="padron.cargando || (form.cuit || '').replace(/\D/g, '').length !== 11" title="Trae razón social, domicilio y condición IVA del padrón de ARCA" @click="consultarPadron">{{ padron.cargando ? '…' : 'Padrón' }}</button></div><p v-if="form.errors.cuit" class="text-carmin text-xs mt-1">{{ form.errors.cuit }}</p><p v-if="padron.msg" class="text-xs mt-1" :class="padron.ok ? 'text-emerald-700' : 'text-carmin'">{{ padron.msg }}</p></div>
      <template v-if="form.condicion_iva === 'Exterior'">
        <div><label class="label">País (ARCA)</label><select v-model="form.pais_codigo" class="input" @change="form.cuit_pais = cuitPais[form.pais_codigo] ?? form.cuit_pais"><option :value="null">Elegir…</option><option v-for="(n, k) in paises" :key="k" :value="k">{{ n }}</option></select></div>
        <div><label class="label">CUIT país (tabla ARCA)</label><input v-model="form.cuit_pais" class="input tabular-nums" placeholder="55000002002" /><p class="text-[11px] text-marca-muted mt-1">Persona jurídica del país; si ARCA informa otro, corregilo acá.</p></div>
        <div><label class="label">Identificación fiscal en su país</label><input v-model="form.id_impositivo" class="input" placeholder="CNPJ, RUT, VAT…" /></div>
      </template>
      <div><label class="label">Tipo de cliente</label><select v-model="form.tipo_cliente_id" class="input" @change="aplicarTipo"><option :value="null">Sin tipo</option><option v-for="t in tipos" :key="t.id" :value="t.id">{{ t.nombre }}</option></select></div>
      <div><label class="label">Lista de precios</label><select v-model.number="form.lista_precios" class="input"><option v-for="n in 6" :key="n" :value="n">Lista {{ n }}</option></select></div>
      <div><label class="label">Días de pago (cta. cte.)</label><input v-model.number="form.dias_pago" type="number" min="0" class="input" /></div>
      <div><label class="label">Descuento %</label><input v-model.number="form.descuento" type="number" min="0" max="100" step="any" class="input" /></div>
      <div><label class="label">Límite de crédito (0 = sin límite)</label><input v-model.number="form.credit_limit" type="number" min="0" class="input" /></div>
      <div><label class="label">Interés por mora (% mensual)</label><input v-model.number="form.interes_mora" type="number" min="0" step="any" class="input" /></div>
      <div><label class="label">Vendedor asignado</label><select v-model="form.vendedor_id" class="input"><option :value="null">Sin vendedor</option><option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option></select></div>
      <div class="flex flex-col gap-1 text-sm mt-2 sm:mt-6">
        <label class="flex items-center gap-2"><input v-model="form.percepcion_iibb" type="checkbox" class="accent-carmin" /> Aplica percepción IIBB</label>
        <label class="flex items-center gap-2" title="Cada artículo que se le factura queda con ese precio para la próxima vez (no pisa los pactados a mano)."><input v-model="form.recordar_precio" type="checkbox" class="accent-carmin" data-recordar-precio /> Recordar el último precio facturado</label>
        <label class="flex items-center gap-2"><input v-model="form.percepcion_iva" type="checkbox" class="accent-carmin" /> Aplica percepción IVA (RG 2408)</label>
        <label class="flex items-center gap-2"><input v-model="form.percepcion_ganancias" type="checkbox" class="accent-carmin" /> Aplica percepción Ganancias</label>
      </div>
      <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" /></div>
      <div><label class="label">Teléfono / WhatsApp</label><input v-model="form.phone" class="input" /></div>
      <div class="sm:col-span-2"><label class="label">Dirección</label><input v-model="form.address" class="input" /></div>
      <div><label class="label">Ciudad</label><input v-model="form.city" class="input" /></div>
      <div><label class="label">Provincia</label><input v-model="form.province" class="input" /></div>
      <div class="sm:col-span-2"><label class="label">Notas</label><textarea v-model="form.notes" rows="2" class="input"></textarea></div>
      <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="accent-carmin" /> Activo</label>
    </form>
    <template #pie>
      <button class="btn-secondary" @click="$emit('cerrar')">Cancelar</button>
      <button class="btn-primary" :disabled="form.processing" @click="guardar">Guardar</button>
    </template>
  </Modal>
</template>

<script setup>
import { reactive, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ abierto: Boolean, cliente: Object, tipos: Array, condicionesIva: Array, vendedores: { type: Array, default: () => [] }, paises: { type: Object, default: () => ({}) }, cuitPais: { type: Object, default: () => ({}) } })
const emit = defineEmits(['cerrar'])
const vacio = { id: null, name: '', condicion_iva: 'Consumidor Final', cuit: '', tipo_cliente_id: null, lista_precios: 1, dias_pago: 0, descuento: 0, credit_limit: 0, percepcion_iibb: false, percepcion_iva: false, percepcion_ganancias: false, pais_codigo: null, cuit_pais: '', id_impositivo: '', interes_mora: 0, vendedor_id: null, recordar_precio: false, email: '', phone: '', address: '', city: '', province: '', notes: '', is_active: true }
const form = useForm({ ...vacio })
watch(() => props.abierto, v => { if (v) { form.clearErrors(); Object.assign(form, { ...vacio, ...(props.cliente ?? {}) }) } })
function aplicarTipo() { const t = props.tipos.find(x => x.id === form.tipo_cliente_id); if (t) { form.lista_precios = t.lista_precios; form.dias_pago = t.dias_pago; form.descuento = Number(t.descuento); form.credit_limit = Number(t.limite_credito) } }
const padron = reactive({ cargando: false, msg: '', ok: false })
async function consultarPadron() {
  padron.cargando = true; padron.msg = ''
  try {
    const r = await fetch(`/clientes/padron/${(form.cuit || '').replace(/\D/g, '')}`, { headers: { Accept: 'application/json' } }); const d = await r.json()
    if (!r.ok) { padron.ok = false; padron.msg = d.error ?? 'No se pudo consultar.'; return }
    if (!form.name) form.name = d.nombre; form.cuit = d.cuit; form.condicion_iva = props.condicionesIva.includes(d.condicion_iva) ? d.condicion_iva : form.condicion_iva
    if (!form.address && d.direccion) form.address = d.direccion; if (!form.city && d.localidad) form.city = d.localidad; if (!form.province && d.provincia) form.province = d.provincia
    padron.ok = true; padron.msg = `ARCA: ${d.nombre} · ${d.condicion_iva}`
  } catch (e) { padron.ok = false; padron.msg = 'No se pudo consultar el padrón.' } finally { padron.cargando = false }
}
function guardar() { form.post(form.id ? `/clientes/${form.id}` : '/clientes', { preserveScroll: true, onSuccess: () => emit('cerrar') }) }
</script>
