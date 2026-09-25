<template>
  <AppLayout titulo="Etiquetas de ubicaciones">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4 no-print">
      <div><Link :href="`/stock/almacen`" class="text-xs text-marca-muted hover:text-carmin">← Almacén</Link><h1 class="page-title">Etiquetas de ubicaciones</h1><p class="page-subtitle">{{ deposito }} · {{ ubicaciones.length }} ubicaciones. Pegalas en cada estante: la pistola lee el código y el sistema sabe dónde estás.</p></div>
      <div class="flex gap-2 items-end">
        <div><label class="label">Formato</label><select v-model="formato" class="input"><option value="grande">Grande (A4, 8 por hoja)</option><option value="media">Mediana (A4, 24 por hoja)</option><option value="rollo">Rollo 100×50 mm (térmica)</option></select></div>
        <button class="btn-primary" :disabled="!sel.length" @click="imprimir">Imprimir {{ sel.length }}</button>
      </div>
    </div>
    <div class="card mb-4 no-print">
      <div class="flex flex-wrap gap-2 mb-2 text-xs"><button class="text-violeta font-semibold" @click="sel = ubicaciones.map(u => u.id)">Todas</button><button class="text-violeta font-semibold" @click="sel = []">Ninguna</button></div>
      <div class="flex flex-wrap gap-1.5"><label v-for="u in ubicaciones" :key="u.id" class="badge cursor-pointer" :class="sel.includes(u.id) ? 'bg-violeta text-white' : 'bg-gris-light text-marca-muted'"><input v-model="sel" type="checkbox" :value="u.id" class="hidden" />{{ u.codigo }}</label></div>
    </div>
    <div id="hoja" :class="`h-${formato}`" class="flex flex-wrap gap-2" data-e2e="hoja-ubicaciones">
      <div v-for="u in elegidas" :key="u.id" class="et-ubic" :class="`t-${formato}`">
        <p class="et-emp">{{ empresa }}</p>
        <p class="et-cod">{{ u.codigo }}</p>
        <p class="et-det">{{ [u.pasillo && 'Pasillo ' + u.pasillo, u.estante && 'Estante ' + u.estante, u.nivel && 'Nivel ' + u.nivel].filter(Boolean).join(' · ') }}</p>
        <canvas class="bc-ubic" :data-code="u.codigo"></canvas>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUpdated, nextTick, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import JsBarcode from 'jsbarcode'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ ubicaciones: Array, deposito: String, empresa: String })
const formato = ref('grande')
const sel = ref(props.ubicaciones.map(u => u.id))
const elegidas = computed(() => props.ubicaciones.filter(u => sel.value.includes(u.id)))
function dibujar() {
  document.querySelectorAll('canvas.bc-ubic').forEach(el => {
    try { JsBarcode(el, el.dataset.code, { format: 'CODE128', displayValue: false, height: formato.value === 'media' ? 34 : 60, width: formato.value === 'media' ? 1.4 : 2.2, margin: 0 }) } catch (e) {}
  })
}
onMounted(dibujar); onUpdated(dibujar); watch(formato, () => nextTick(dibujar))
function imprimir() { window.print() }
</script>

<style>
.et-ubic { border: 1px dashed #bdb6ad; border-radius: 6px; padding: 3mm; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; overflow: hidden; background: #fff; color: #1c1a18; }
.et-emp { font-size: 8px; text-transform: uppercase; letter-spacing: .12em; color: #6f6a62; }
.et-cod { font-family: ui-monospace, monospace; font-weight: 900; font-size: 30px; line-height: 1.1; }
.et-det { font-size: 10px; color: #4a4640; margin-bottom: 2mm; }
.et-ubic canvas { max-width: 100%; }
.t-grande { width: 100mm; height: 68mm; } .t-grande .et-cod { font-size: 40px; }
.t-media { width: 64mm; height: 34mm; } .t-media .et-cod { font-size: 20px; } .t-media .et-det { font-size: 8px; margin-bottom: 1mm; }
.t-rollo { width: 100mm; height: 50mm; } .t-rollo .et-cod { font-size: 32px; }
@media print {
  body * { visibility: hidden; } .no-print { display: none !important; }
  #hoja, #hoja * { visibility: visible; }
  #hoja { position: absolute; left: 0; top: 0; width: 100%; }
  .et-ubic { border-color: #ddd; }
  .h-rollo .et-ubic { page-break-after: always; border: 0; }
  @page { margin: 6mm; }
}
</style>
