<template>
  <AppLayout :titulo="`Conciliación #${lote.id}`">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/fondos/conciliar" class="text-xs text-marca-muted hover:text-carmin">← Conciliar</Link><h1 class="page-title">{{ lote.origen }} · {{ lote.fondo }}</h1><p class="page-subtitle">{{ lote.archivo }} · {{ lote.periodo }} · {{ lote.usuario }} · <span class="font-semibold">{{ lote.estado }}</span></p></div>
      <a :href="`/fondos/conciliar/${lote.id}/exportar`" class="btn-secondary">Bajar lista para tildar</a>
    </div>

    <div v-for="(a, i) in r.avisos" :key="i" class="rounded-xl bg-amber-50 text-amber-800 px-4 py-2 mb-2 text-sm">{{ a }}</div>
    <p class="text-xs text-marca-muted mb-3" data-e2e="control">Leí {{ lote.control.filas_leidas }} de {{ lote.control.filas_datos }} filas con datos<span v-if="lote.control.total_archivo !== null"> · total del archivo {{ moneda(lote.control.total_archivo) }} {{ lote.control.cierra ? '✓ cierra' : '✗ no cierra' }}</span><span v-else> · el archivo no trae un total propio para comparar</span>.</p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-e2e="numeros">
      <div class="card py-3"><p class="lbl">Cerró</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(r.resumen.cerro, 0) }}</p><p class="text-xs text-marca-muted">{{ r.resumen.listo_n }} cupones<span v-if="r.resumen.ya_tildado_n"> · {{ r.resumen.ya_tildado_n }} ya tildados</span></p></div>
      <div class="card py-3"><p class="lbl">Todavía no te pagaron</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(r.resumen.no_pagaron, 0) }}</p><p class="text-xs text-marca-muted">{{ r.resumen.no_pagaron_n }} cupones</p></div>
      <div class="card py-3"><p class="lbl">Comisiones e impuestos</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(r.resumen.comisiones, 0) }}</p><p v-if="r.pactado?.de_mas" class="text-xs text-carmin">{{ r.pactado.de_mas }} con comisión de más · {{ moneda(r.pactado.de_mas_monto) }}</p><p v-if="r.pactado?.tarde" class="text-xs text-amber-700">{{ r.pactado.tarde }} pagadas después de {{ r.pactado.plazo }} días</p></div>
      <div class="card py-3"><p class="lbl">A revisar</p><p class="text-xl font-extrabold tabular-nums" :class="r.resumen.revisar_n ? 'text-amber-700' : ''" data-e2e="revisar-n">{{ r.resumen.revisar_n }}</p></div>
    </div>

    <div class="flex gap-1.5 text-xs mb-2"><button v-for="t in tabs" :key="t[0]" class="rounded-full px-3 py-1 font-semibold border" :class="tab === t[0] ? 'bg-marca-texto text-white border-marca-texto' : 'border-marca-borde'" @click="tab = t[0]" :data-tab="t[0]">{{ t[1] }}</button></div>

    <div class="card p-0 overflow-x-auto mb-4">
      <table v-if="tab === 'listo'" class="table text-sm"><thead><tr><th>Fecha</th><th class="text-right">Importe</th><th>Cupón archivo</th><th>Cupón sistema</th><th>Cliente</th><th>Cómo</th><th>Acreditación</th><th></th></tr></thead>
        <tbody><tr v-for="f in r.listo" :key="f.fila"><td>{{ f.fecha_venta }}</td><td class="text-right tabular-nums">{{ moneda(f.importe) }}</td><td>{{ f.cupon }}</td><td>{{ f.cupon_numero }}</td><td>{{ f.cliente }} <span class="text-xs text-marca-muted">{{ f.recibo }}</span></td><td class="text-xs text-marca-muted">{{ f.como }}</td><td class="text-xs">{{ f.acreditacion }}<span v-if="f.comision_de_mas" class="text-carmin"> · +{{ moneda(f.comision_de_mas) }}</span></td><td><button v-if="editable" class="text-xs text-marca-muted hover:text-carmin" @click="resolver(f.fila, null, true)">volver a revisar</button></td></tr>
          <tr v-for="f in r.ya_tildado" :key="'y' + f.fila" class="opacity-60"><td>{{ f.fecha_venta }}</td><td class="text-right tabular-nums">{{ moneda(f.importe) }}</td><td>{{ f.cupon }}</td><td>{{ f.cupon_numero }}</td><td>{{ f.cliente }}</td><td class="text-xs">ya tildado</td><td></td><td></td></tr>
          <tr v-if="!r.listo.length && !r.ya_tildado.length"><td colspan="8" class="text-center text-marca-muted py-6">Nada cerró todavía.</td></tr></tbody></table>
      <table v-if="tab === 'no_pagaron'" class="table text-sm"><thead><tr><th>Fecha</th><th>Cupón</th><th>Tarjeta</th><th>Cliente</th><th class="text-right">Monto</th><th class="text-right">Días</th></tr></thead>
        <tbody><tr v-for="c in r.no_pagaron" :key="c.id"><td>{{ c.fecha }}</td><td>{{ c.numero }}</td><td>{{ c.tarjeta }}</td><td>{{ c.cliente }}</td><td class="text-right tabular-nums">{{ moneda(c.monto) }}</td><td class="text-right">{{ c.dias }}</td></tr>
          <tr v-if="!r.no_pagaron.length"><td colspan="6" class="text-center text-emerald-700 py-6">Te pagaron todo lo del período.</td></tr></tbody></table>
      <div v-if="tab === 'acreditaciones'" class="p-4 space-y-2 text-sm">
        <div v-for="a in r.acreditaciones" :key="a.clave" class="border-b border-marca-borde pb-2"><b>{{ a.clave }}</b> <span class="text-xs text-marca-muted">{{ a.fecha_pago }} · {{ a.cupones }} cupones</span><p>{{ a.texto }}</p><p class="text-xs text-marca-muted">Comisión {{ moneda(a.comision) }} · IVA {{ moneda(a.iva) }} · Ret. IVA {{ moneda(a.ret_iva) }} · IIBB {{ moneda(a.ret_iibb) }} · Gan. {{ moneda(a.ret_ganancias) }}<span v-if="a.otros"> · otros {{ moneda(a.otros) }}</span></p></div>
        <p v-if="!r.acreditaciones.length" class="text-marca-muted">Sin acreditaciones.</p>
      </div>
      <table v-if="tab === 'revisar'" class="table text-sm"><thead><tr><th>Fecha</th><th class="text-right">Importe</th><th>Cupón</th><th>Por qué</th><th>Qué hacer</th></tr></thead>
        <tbody><tr v-for="f in r.revisar" :key="f.fila" data-e2e="fila-revisar"><td>{{ f.fecha_venta }}</td><td class="text-right tabular-nums">{{ moneda(f.importe) }}</td><td>{{ f.cupon ?? '—' }}</td><td class="text-xs">{{ f.motivo }}</td>
          <td class="text-xs"><template v-if="editable"><button v-for="c in f.candidatos" :key="c.id" class="block text-violeta font-semibold" @click="resolver(f.fila, c.id)">Es el {{ c.numero }} · {{ c.fecha }} · {{ c.cliente }}</button><button class="block text-marca-muted" @click="resolver(f.fila, null)" data-e2e="descartar">No es de este sistema</button></template></td></tr>
          <tr v-if="!r.revisar.length"><td colspan="5" class="text-center text-emerald-700 py-6">Nada para revisar.</td></tr></tbody></table>
    </div>

    <!-- 35.4 Asentar -->
    <div class="card" data-e2e="asentar">
      <h2 class="font-bold mb-1">Asentar</h2>
      <template v-if="lote.estado === 'asentada'"><p class="text-sm text-emerald-700">Asentado el {{ lote.asentado_en }} por {{ lote.asentado?.usuario }}: {{ lote.asentado?.liquidaciones?.length }} liquidaciones.</p><button v-if="puede('fondos','anular')" class="btn-secondary mt-2" @click="confirm('¿Deshacer? Las liquidaciones se anulan y los cupones vuelven a cartera.') && router.post(`/fondos/conciliar/${lote.id}/deshacer`)" data-e2e="deshacer">Deshacer</button></template>
      <p v-else-if="lote.estado === 'deshecha'" class="text-sm text-marca-muted">Este lote se deshizo.</p>
      <template v-else>
        <p class="text-sm mb-2">{{ previa.texto }}</p>
        <label class="text-sm">El neto entra en <select :value="lote.banco_id" class="input !w-auto !py-1 inline-block" @change="router.post(`/fondos/conciliar/${lote.id}/banco`, { banco_id: $event.target.value }, { preserveScroll: true })" data-e2e="banco"><option :value="null">Elegí…</option><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select></label>
        <div v-for="a in previa.acreditaciones" :key="a.clave" class="mt-3 text-sm"><b>{{ a.clave }}</b> <span class="text-xs text-marca-muted">{{ a.fecha_pago }}</span>
          <table class="table text-xs mt-1"><thead><tr><th>Cuenta</th><th class="text-right">Debe</th><th class="text-right">Haber</th></tr></thead><tbody><tr v-for="(l, i) in a.lineas" :key="i"><td>{{ l.cuenta }}</td><td class="text-right tabular-nums">{{ l.debe ? moneda(l.debe) : '' }}</td><td class="text-right tabular-nums">{{ l.haber ? moneda(l.haber) : '' }}</td></tr><tr class="font-bold"><td>Total</td><td class="text-right tabular-nums">{{ moneda(a.debe) }}</td><td class="text-right tabular-nums">{{ moneda(a.haber) }}</td></tr></tbody></table>
          <p v-for="p in a.problemas" :key="p" class="text-carmin text-xs">{{ p }}</p></div>
        <p v-for="f in previa.faltan" :key="f" class="text-carmin text-xs mt-2">Falta: {{ f }}</p>
        <div class="flex gap-2 mt-3"><button class="btn-secondary" :disabled="!previa.puede" @click="router.post(`/fondos/conciliar/${lote.id}/probar`, {}, { preserveScroll: true })" data-e2e="probar">Ver qué escribiría</button><button class="btn-primary" :disabled="!previa.puede || !lote.probado" @click="router.post(`/fondos/conciliar/${lote.id}/asentar`, {}, { preserveScroll: true })" data-e2e="asentar-btn">Asentar {{ previa.acreditaciones.length }} liquidaciones</button></div>
        <p class="text-[11px] text-marca-muted mt-1">La prueba no escribe nada y es obligatoria. Se asienta todo o nada, y se puede deshacer.</p>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ lote: Object, previa: Object, bancos: Array })
const { puede } = usePermisos()
const r = computed(() => props.lote.resultado)
const editable = computed(() => props.lote.estado === 'propuesta')
const tab = ref(props.lote.resultado.resumen.revisar_n ? 'revisar' : 'listo')
const tabs = computed(() => [['listo', `Listo para tildar (${r.value.resumen.listo_n})`], ['no_pagaron', `Todavía no te pagaron (${r.value.resumen.no_pagaron_n})`], ['acreditaciones', `Comisiones e impuestos (${r.value.acreditaciones.length})`], ['revisar', `A revisar (${r.value.resumen.revisar_n})`]])
const resolver = (fila, cupon_id) => router.post(`/fondos/conciliar/${props.lote.id}/resolver`, { fila, cupon_id }, { preserveScroll: true })
</script>
<style scoped>.lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #6b6b6b }</style>
