<template>
  <AppLayout titulo="Impuestos">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">Percepciones y retenciones</h2>
        <p class="text-sm text-marca-muted mb-4">Si sos agente de percepción o retención, el sistema calcula solo las percepciones de IIBB en las facturas y sugiere las retenciones al pagar a proveedores. Las alícuotas por CUIT salen del padrón; si no hay, se usa la configurada.</p>
        <div class="grid md:grid-cols-2 gap-5">
          <div class="rounded-xl border border-marca-borde p-4">
            <label class="flex items-center gap-2 font-semibold text-sm"><input v-model="form.impuestos.percepcion_iibb.activo" type="checkbox" class="accent-carmin" /> Percepción de IIBB en ventas</label>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div><label class="label">Jurisdicción</label><select v-model="form.impuestos.percepcion_iibb.jurisdiccion" class="input"><option v-for="(n, k) in jurisdicciones" :key="k" :value="k">{{ n }}</option></select></div>
              <div><label class="label">Alícuota general %</label><input v-model.number="form.impuestos.percepcion_iibb.alicuota" type="number" step="any" class="input" /></div>
              <div><label class="label">Mínimo no percibible $</label><input v-model.number="form.impuestos.percepcion_iibb.minimo" type="number" step="any" class="input" /></div>
              <label class="flex items-center gap-2 text-sm mt-6"><input v-model="form.impuestos.percepcion_iibb.solo_padron" type="checkbox" class="accent-carmin" /> Solo si figura en el padrón</label>
            </div>
          </div>
          <div class="rounded-xl border border-marca-borde p-4">
            <label class="flex items-center gap-2 font-semibold text-sm"><input v-model="form.impuestos.retencion_iibb.activo" type="checkbox" class="accent-carmin" /> Retención de IIBB en pagos</label>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div><label class="label">Jurisdicción</label><select v-model="form.impuestos.retencion_iibb.jurisdiccion" class="input"><option v-for="(n, k) in jurisdicciones" :key="k" :value="k">{{ n }}</option></select></div>
              <div><label class="label">Alícuota general %</label><input v-model.number="form.impuestos.retencion_iibb.alicuota" type="number" step="any" class="input" /></div>
              <div><label class="label">Mínimo $</label><input v-model.number="form.impuestos.retencion_iibb.minimo" type="number" step="any" class="input" /></div>
            </div>
          </div>
          <div class="rounded-xl border border-marca-borde p-4">
            <label class="flex items-center gap-2 font-semibold text-sm"><input v-model="form.impuestos.retencion_ganancias.activo" type="checkbox" class="accent-carmin" /> Retención de Ganancias (RG 830)</label>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div><label class="label">Bienes %</label><input v-model.number="form.impuestos.retencion_ganancias.alicuota_bienes" type="number" step="any" class="input" /></div>
              <div><label class="label">Servicios %</label><input v-model.number="form.impuestos.retencion_ganancias.alicuota_servicios" type="number" step="any" class="input" /></div>
              <div><label class="label">Mínimo mensual $</label><input v-model.number="form.impuestos.retencion_ganancias.minimo" type="number" step="any" class="input" /></div>
              <label class="flex items-center gap-2 text-sm mt-6"><input v-model="form.impuestos.retencion_ganancias.acumula_mes" type="checkbox" class="accent-carmin" /> Acumula pagos del mes</label>
            </div>
          </div>
          <div class="rounded-xl border border-marca-borde p-4">
            <label class="flex items-center gap-2 font-semibold text-sm"><input v-model="form.impuestos.retencion_iva.activo" type="checkbox" class="accent-carmin" /> Retención de IVA</label>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div><label class="label">Alícuota %</label><input v-model.number="form.impuestos.retencion_iva.alicuota" type="number" step="any" class="input" /></div>
              <div><label class="label">Mínimo $</label><input v-model.number="form.impuestos.retencion_iva.minimo" type="number" step="any" class="input" /></div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
              <div><label class="label">CBU para Factura de Crédito MiPyME</label><input v-model="form.cbu_fce" class="input tabular-nums" maxlength="22" placeholder="22 dígitos" /><p class="text-[11px] text-marca-muted mt-1">Obligatorio para emitir FCE a empresas grandes.</p></div>
              <div><label class="label">Mes de cierre de ejercicio</label><select v-model.number="form.cierre_mes" class="input"><option v-for="(m, i) in meses" :key="i" :value="i + 1">{{ m }}</option></select></div>
            </div>
          </div>
          <div class="rounded-xl border border-marca-borde p-4">
            <label class="flex items-center gap-2 font-semibold text-sm"><input v-model="form.impuestos.percepcion_iva.activo" type="checkbox" class="accent-carmin" /> Percepción de IVA en ventas (RG 2408)</label>
            <p class="text-[11px] text-marca-muted mt-1">Si sos agente de percepción de IVA. Se aplica sobre el neto gravado a los clientes marcados en su ficha con "Aplica percepción IVA". Va a ARCA como tributo 6.</p>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div><label class="label">Alícuota %</label><input v-model.number="form.impuestos.percepcion_iva.alicuota" type="number" step="any" class="input" /></div>
              <div><label class="label">Mínimo no percibible $</label><input v-model.number="form.impuestos.percepcion_iva.minimo" type="number" step="any" class="input" /></div>
              <label class="flex items-center gap-2 text-sm col-span-2"><input v-model="form.impuestos.percepcion_iva.solo_ri" type="checkbox" class="accent-carmin" /> Solo a responsables inscriptos</label>
            </div>
          </div>
          <div class="rounded-xl border border-marca-borde p-4">
            <label class="flex items-center gap-2 font-semibold text-sm"><input v-model="form.impuestos.percepcion_ganancias.activo" type="checkbox" class="accent-carmin" /> Percepción de Ganancias en ventas</label>
            <p class="text-[11px] text-marca-muted mt-1">Regímenes de percepción de Ganancias por actividad. Se aplica sobre el neto gravado a los clientes marcados con "Aplica percepción Ganancias" (nunca a monotributistas). Va a ARCA como tributo 9.</p>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div><label class="label">Alícuota %</label><input v-model.number="form.impuestos.percepcion_ganancias.alicuota" type="number" step="any" class="input" /></div>
              <div><label class="label">Mínimo no percibible $</label><input v-model.number="form.impuestos.percepcion_ganancias.minimo" type="number" step="any" class="input" /></div>
            </div>
          </div>
        </div>
        <div class="flex justify-end mt-4"><button class="btn-primary" :disabled="form.processing" @click="form.post('/configuracion/impuestos', { preserveScroll: true })">Guardar configuración</button></div>
      </div>

      <div class="card">
        <h2 class="font-bold mb-1">Padrones de IIBB</h2>
        <p class="text-sm text-marca-muted mb-3">Subí el archivo oficial (ARBA, AGIP…) o un CSV simple <code>CUIT;percepción;retención</code>. Los padrones son compartidos por todas las empresas del sistema.</p>
        <table class="table text-sm mb-4">
          <thead><tr><th>Jurisdicción</th><th class="text-right">CUIT cargados</th><th>Actualizado</th></tr></thead>
          <tbody>
            <tr v-for="p in padrones" :key="p.jurisdiccion"><td>{{ p.nombre }}</td><td class="text-right tabular-nums">{{ entero(p.n) }}</td><td class="text-marca-muted">{{ p.actualizado }}</td></tr>
            <tr v-if="!padrones.length"><td colspan="3" class="text-center text-marca-muted py-4">Todavía no hay padrones cargados.</td></tr>
          </tbody>
        </table>
        <form @submit.prevent="padron.post('/configuracion/impuestos/padron', { forceFormData: true, preserveScroll: true, onSuccess: () => padron.reset() })" class="grid sm:grid-cols-3 gap-3 items-end">
          <div><label class="label">Jurisdicción</label><select v-model="padron.jurisdiccion" class="input"><option v-for="(n, k) in jurisdicciones" :key="k" :value="k">{{ n }}</option></select></div>
          <div><label class="label">Archivo</label><input type="file" @change="padron.archivo = $event.target.files[0]" class="input !py-1.5 text-xs" /></div>
          <button class="btn-secondary" :disabled="padron.processing || !padron.archivo">Importar padrón</button>
          <p v-if="padron.errors.archivo" class="text-carmin text-xs sm:col-span-3">{{ padron.errors.archivo }}</p>
        </form>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Índice IPC (ajuste por inflación)</h2><button class="btn-secondary !py-1 text-xs" :disabled="act.processing" @click="act.post('/configuracion/impuestos/ipc/actualizar', { preserveScroll: true })">Actualizar desde INDEC</button></div>
        <p class="text-sm text-marca-muted mb-3">Se usa para el ajuste por inflación contable y para ver estadísticas en "pesos de hoy". Podés cargarlo a mano si no hay conexión.</p>
        <p v-if="act.errors.ipc" class="text-carmin text-xs mb-2">{{ act.errors.ipc }}</p>
        <form @submit.prevent="ipc.post('/configuracion/impuestos/ipc', { preserveScroll: true, onSuccess: () => ipc.reset() })" class="flex flex-wrap gap-2 items-end mb-3">
          <div><label class="label">Período</label><input v-model="ipc.periodo" type="month" class="input" /></div>
          <div><label class="label">Índice</label><input v-model.number="ipc.valor" type="number" step="any" class="input" placeholder="ej. 7864,13" /></div>
          <button class="btn-primary" :disabled="ipc.processing || !ipc.periodo || !ipc.valor">Guardar</button>
        </form>
        <div class="max-h-64 overflow-y-auto">
          <table class="table text-sm"><thead><tr><th>Período</th><th class="text-right">Índice</th><th>Fuente</th></tr></thead>
            <tbody><tr v-for="i in ipc_lista" :key="i.periodo"><td class="tabular-nums">{{ i.periodo }}</td><td class="text-right tabular-nums">{{ Number(i.valor).toLocaleString('es-AR', { minimumFractionDigits: 2 }) }}</td><td class="text-marca-muted">{{ i.fuente }}</td></tr>
            <tr v-if="!ipc_lista.length"><td colspan="3" class="text-center text-marca-muted py-4">Sin índices cargados.</td></tr></tbody></table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import { entero } from '@/util/formato'
const props = defineProps({ config: Object, cbu_fce: String, cierre_mes: Number, jurisdicciones: Object, padrones: Array, ipc: Array })
const ipc_lista = props.ipc
const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const form = useForm({ impuestos: JSON.parse(JSON.stringify(props.config)), cbu_fce: props.cbu_fce ?? '', cierre_mes: props.cierre_mes })
const padron = useForm({ jurisdiccion: 'ARBA', archivo: null })
const ipc = useForm({ periodo: '', valor: null })
const act = useForm({})
</script>
