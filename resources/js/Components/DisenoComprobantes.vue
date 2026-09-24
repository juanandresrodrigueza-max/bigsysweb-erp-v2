<template>
  <div class="card lg:col-span-2 space-y-4" data-diseno>
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="font-bold">Diseño de comprobantes</h2>
        <p class="text-xs text-marca-muted mt-0.5">Facturas, notas, remitos, presupuestos, recibos, órdenes de pago, tickets y el catálogo salen con tu logo y tus colores. Lo que exige ARCA (razón social, CUIT, IIBB, inicio de actividades, condición IVA, letra y código, CAE y QR) sale siempre y no se puede sacar.</p>
      </div>
      <a href="/configuracion/empresa/muestra" target="_blank" class="btn-secondary !py-1.5 text-xs" data-ver-muestra>Ver una factura con este diseño</a>
    </div>

    <div class="grid md:grid-cols-2 gap-5">
      <div class="space-y-3">
        <div>
          <label class="label">Logo</label>
          <div class="flex items-center gap-3">
            <div class="w-28 h-16 rounded-xl border border-dashed border-marca-borde grid place-items-center bg-white overflow-hidden"><img v-if="marca.logo_uri" :src="marca.logo_uri" alt="Logo" class="max-h-14 max-w-[104px]" /><span v-else class="text-[11px] text-marca-muted">Sin logo</span></div>
            <div class="flex flex-col gap-1">
              <label class="btn-secondary !py-1 text-xs cursor-pointer">{{ marca.logo_uri ? 'Cambiar' : 'Subir logo' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="subirLogo" data-logo-input /></label>
              <button v-if="marca.logo_uri" type="button" class="text-xs text-marca-muted hover:text-carmin" @click="router.delete('/configuracion/empresa/logo', { preserveScroll: true })">Quitar</button>
            </div>
          </div>
          <p class="text-[11px] text-marca-muted mt-1">PNG, JPG o WEBP de hasta 1 MB. Mejor con fondo transparente.</p>
          <p v-if="errLogo" class="text-xs text-carmin mt-1">{{ errLogo }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Color principal</label><div class="flex gap-2"><input v-model="f.color_primario" type="color" class="h-10 w-12 rounded-lg border border-marca-borde" data-color-primario /><input v-model="f.color_primario" class="input font-mono text-xs" maxlength="7" /></div></div>
          <div><label class="label">Color secundario</label><div class="flex gap-2"><input v-model="f.color_secundario" type="color" class="h-10 w-12 rounded-lg border border-marca-borde" /><input v-model="f.color_secundario" class="input font-mono text-xs" maxlength="7" /></div></div>
        </div>
        <p class="text-[11px] text-marca-muted -mt-1">El principal va en el nombre, la letra y el total; el secundario en los títulos de la tabla. El texto encima se pone blanco o negro solo, para que se lea.</p>
        <div><label class="label">Estilo del encabezado</label><select v-model="f.estilo" class="input" data-estilo><option v-for="(l, k) in estilos" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Datos extra debajo de la empresa</label><textarea v-model="f.datos_extra" rows="3" class="input text-sm" placeholder="www.tuempresa.com.ar&#10;Instagram @tuempresa&#10;CBU 0110… · Alias TUEMPRESA.PAGOS" data-datos-extra></textarea></div>
        <div><label class="label">Leyenda al pie</label><textarea v-model="f.pie" rows="2" class="input text-sm" placeholder="Gracias por su compra · Cambios dentro de los 30 días con este comprobante" data-pie></textarea></div>
        <div class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
          <label v-for="(l, k) in opciones" :key="k" class="flex items-center gap-2"><input v-model="f.mostrar[k]" type="checkbox" class="accent-carmin" /> {{ l }}</label>
        </div>
        <div class="w-40"><label class="label">Validez del presupuesto (días)</label><input v-model.number="f.validez_presupuesto" type="number" min="1" max="365" class="input" /></div>
        <p v-if="Object.keys(f.errors).length" class="text-xs text-carmin">{{ Object.values(f.errors)[0] }}</p>
        <button type="button" class="btn-primary" :disabled="f.processing" @click="f.post('/configuracion/empresa/marca', { preserveScroll: true })" data-guardar-diseno>Guardar diseño</button>
      </div>

      <!-- Vista previa en vivo: misma estructura que la factura impresa -->
      <div>
        <p class="label mb-1">Vista previa</p>
        <div class="rounded-xl border border-marca-borde overflow-hidden bg-white text-[10px] text-[#1c1a18] shadow-sm" data-preview>
          <div class="grid grid-cols-[1fr_52px_1fr]" :style="f.estilo === 'banda' ? { background: f.color_primario, color: txtP } : f.estilo === 'minimo' ? { borderBottom: `2px solid ${f.color_primario}` } : { borderBottom: '1px solid #d6d1ca' }">
            <div class="p-2.5">
              <img v-if="marca.logo_uri && f.mostrar.logo" :src="marca.logo_uri" class="max-h-8 max-w-[90px] mb-1" alt="" />
              <p class="font-extrabold text-[12px]" :style="{ color: f.estilo === 'banda' ? txtP : f.color_primario }">{{ empresa.razon_social || empresa.name }}</p>
              <p class="opacity-80">{{ [empresa.address, empresa.city].filter(Boolean).join(' · ') || 'Domicilio comercial' }}</p>
              <p class="font-bold">{{ empresa.condicion_iva }}</p>
              <p v-for="(l, i) in lineas" :key="i" class="opacity-70">{{ l }}</p>
            </div>
            <div class="grid place-items-center" :style="f.estilo === 'minimo' ? {} : { borderLeft: '1px solid rgba(0,0,0,.12)', borderRight: '1px solid rgba(0,0,0,.12)' }">
              <span class="text-[22px] font-black leading-none rounded-md px-1.5" :style="f.estilo === 'banda' ? { background: '#fff', color: f.color_primario } : { color: f.color_primario }">A</span>
              <span class="text-[7px] opacity-70">COD. 001</span>
            </div>
            <div class="p-2.5">
              <p class="font-extrabold text-[11px]" :style="{ color: f.estilo === 'banda' ? txtP : f.color_secundario }">FACTURA A</p>
              <p class="font-bold">N° 0001-00000123</p>
              <p class="opacity-80">CUIT {{ empresa.cuit || '30-00000000-0' }}<br>IIBB {{ empresa.iibb || '—' }} · Inicio {{ empresa.inicio_actividades ? empresa.inicio_actividades.split('-').reverse().join('/') : '—' }}</p>
            </div>
          </div>
          <div class="px-2.5 py-1.5 border-b border-[#d6d1ca]"><b>Cliente:</b> Cliente de ejemplo S.A. · <b>CUIT</b> 30-70012345-6<span v-if="f.mostrar.vendedor"> · <b>Vendedor:</b> Juan</span></div>
          <table class="w-full">
            <thead><tr :style="{ background: f.color_secundario, color: txtS }"><th v-if="f.mostrar.codigo" class="text-left px-2 py-1 font-bold">Código</th><th class="text-left px-2 py-1 font-bold">Descripción</th><th class="text-right px-2 py-1 font-bold">Cant.</th><th v-if="f.mostrar.bonificacion" class="text-right px-2 py-1 font-bold">Bonif.</th><th class="text-right px-2 py-1 font-bold">Subtotal</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in [['LAD12', 'Ladrillo hueco 12x18x33', '500', '5%', '$ 242.250,00'], ['CEM50', 'Cemento x 50 kg', '20', '', '$ 196.000,00']]" :key="i" :style="i % 2 ? { background: suave } : {}"><td v-if="f.mostrar.codigo" class="px-2 py-1">{{ r[0] }}</td><td class="px-2 py-1">{{ r[1] }}</td><td class="px-2 py-1 text-right">{{ r[2] }}</td><td v-if="f.mostrar.bonificacion" class="px-2 py-1 text-right">{{ r[3] }}</td><td class="px-2 py-1 text-right">{{ r[4] }}</td></tr>
            </tbody>
          </table>
          <div class="flex justify-end px-2.5 py-2"><div class="w-36 flex justify-between font-extrabold text-[12px] pt-1" :style="{ borderTop: `2px solid ${f.color_primario}`, color: f.color_primario }"><span>TOTAL</span><span>$ 530.282,50</span></div></div>
          <div v-if="f.mostrar.saldo" class="mx-2.5 mb-2 p-1.5 border border-[#ddd] rounded">Saldo de su cuenta corriente: <b>$ 1.250.000,00</b></div>
          <div class="flex items-center gap-2 px-2.5 py-2 border-t border-[#d6d1ca] text-[#6f6a62]"><span class="w-7 h-7 bg-[#1c1a18] rounded-sm opacity-80"></span><span>Comprobante Autorizado · CAE 75123456789012 · QR de ARCA</span></div>
          <p v-if="f.pie" class="px-2.5 py-1.5 border-t border-[#eee] text-center whitespace-pre-line">{{ f.pie }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({ marca: Object, estilos: Object, empresa: Object })
const opciones = { logo: 'Mostrar el logo', codigo: 'Código del artículo', bonificacion: 'Columna de bonificación', vendedor: 'Nombre del vendedor', saldo: 'Saldo de cuenta corriente', firma_remito: 'Firma "recibí conforme" en remitos' }
const f = useForm({ color_primario: props.marca.color_primario, color_secundario: props.marca.color_secundario, estilo: props.marca.estilo, datos_extra: props.marca.datos_extra ?? '', pie: props.marca.pie ?? '', validez_presupuesto: props.marca.validez_presupuesto ?? 7, mostrar: { ...props.marca.mostrar } })
const lineas = computed(() => (f.datos_extra || '').split('\n').map(s => s.trim()).filter(Boolean))
// Mismo cálculo que el servidor (WCAG): texto blanco o negro sobre el color.
function textoSobre(hex) {
  const h = (hex || '').replace('#', ''); if (h.length !== 6) return '#fff'
  const l = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
  const lum = 0.2126 * l(parseInt(h.slice(0, 2), 16)) + 0.7152 * l(parseInt(h.slice(2, 4), 16)) + 0.0722 * l(parseInt(h.slice(4, 6), 16))
  return 1.05 / (lum + 0.05) >= (lum + 0.05) / 0.05 ? '#ffffff' : '#1c1a18'
}
const txtP = computed(() => textoSobre(f.color_primario))
const txtS = computed(() => textoSobre(f.color_secundario))
const suave = computed(() => { const h = f.color_primario.replace('#', ''); const m = i => Math.round(255 - (255 - parseInt(h.slice(i, i + 2), 16)) * 0.08); return h.length === 6 ? `rgb(${m(0)},${m(2)},${m(4)})` : '#faf9f7' })
const errLogo = ref('')
function subirLogo(e) {
  const file = e.target.files?.[0]; if (!file) return
  errLogo.value = ''
  router.post('/configuracion/empresa/logo', { logo: file }, { forceFormData: true, preserveScroll: true, onError: er => { errLogo.value = er.logo ?? 'No se pudo subir.' } })
}
</script>
