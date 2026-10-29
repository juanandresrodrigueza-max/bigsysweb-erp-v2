<template>
  <AppLayout titulo="Mis empresas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><p v-if="estudio.es_estudio" class="text-xs font-bold uppercase tracking-widest text-violeta">{{ estudio.marca }}</p><h1 class="page-title">Mis empresas</h1><p class="page-subtitle">Los clientes que atendés con BigSysWeb, de un vistazo. Entrás a cualquiera con un clic; el rol es el que te dio cada empresa.</p></div>
      <div class="flex items-center gap-2"><label class="label !mb-0">Mes</label><input type="month" :value="mes" class="input w-auto !py-1.5" @change="router.get('/contador/empresas', { mes: $event.target.value })" /></div>
    </div>
    <!-- Fase 39: el estudio (precio y vencimientos) y los conjuntos -->
    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card text-sm" data-e2e="estudio">
        <div class="flex justify-between items-center"><h2 class="font-bold">El estudio</h2><button v-if="estudio.puede_editar" class="text-xs text-violeta" @click="editar = !editar">{{ editar ? 'cerrar' : 'configurar' }}</button></div>
        <p class="text-xs text-marca-muted">{{ precio.empresas }} empresas administradas · <b>{{ moneda(precio.total, 0) }}</b> por mes ({{ moneda(precio.promedio, 0) }} promedio)</p>
        <p v-for="d in precio.detalle" :key="d.desde" class="text-[11px] text-marca-muted">De la {{ d.desde }} a la {{ d.hasta }}: {{ d.cantidad }} × {{ moneda(d.precio, 0) }}</p>
        <div v-if="editar" class="mt-2 space-y-1">
          <label class="flex items-center gap-2 text-xs"><input v-model="ef.es_estudio" type="checkbox" class="accent-carmin" /> Somos un estudio contable</label>
          <input v-model="ef.marca" class="input !py-1" placeholder="Nombre del estudio" />
          <p class="label">Precio progresivo</p>
          <div v-for="(t, k) in ef.tramos" :key="k" class="flex gap-1 items-center text-xs">hasta <input v-model.number="t.hasta" type="number" class="input !py-0.5 !w-16" placeholder="∞" /> empresas, $ <input v-model.number="t.precio" type="number" class="input !py-0.5 !w-24" /> c/u <button class="text-carmin" @click="ef.tramos.splice(k, 1)">✕</button></div>
          <button class="text-xs text-violeta" @click="ef.tramos.push({ hasta: null, precio: 0 })">+ tramo</button>
          <p class="label">Vence IVA el día (por terminación de CUIT)</p>
          <div class="grid grid-cols-5 gap-1 text-xs"><label v-for="d in 10" :key="d - 1" class="text-center">{{ d - 1 }}<input v-model.number="ef.vencimientos[String(d - 1)]" type="number" min="1" max="31" class="input !py-0.5 !px-1 text-center" /></label></div>
          <button class="btn-primary !py-1 text-xs w-full" @click="router.post('/contador/estudio', ef, { preserveScroll: true })">Guardar</button>
        </div>
      </div>
      <div class="card text-sm" data-e2e="vencimientos"><h2 class="font-bold mb-1">Vencimientos</h2>
        <div v-for="v in vencimientos" :key="v.id" class="flex justify-between py-0.5 border-t border-marca-borde text-xs"><span>{{ v.empresa }} <span class="text-marca-muted">· CUIT termina en {{ v.terminacion }}</span></span><span :class="v.dias <= 3 ? 'text-carmin font-bold' : ''">{{ v.que }} {{ v.fecha.split('-').reverse().join('/') }}</span></div>
        <p v-if="!vencimientos.length" class="text-xs text-marca-muted">Cargá el CUIT de las empresas para ver sus vencimientos.</p></div>
      <div class="card text-sm" data-e2e="conjuntos"><h2 class="font-bold mb-1">Conjuntos</h2><p class="text-xs text-marca-muted mb-1">Mirá varias empresas juntas (sin contar dos veces lo que se venden entre ellas).</p>
        <Link v-for="c in conjuntos" :key="c.id" :href="`/conjuntos/${c.id}`" class="flex justify-between py-0.5 border-t border-marca-borde"><span>{{ c.nombre }}</span><span class="text-xs text-marca-muted">{{ c.empresas.length }} empresas →</span></Link>
        <div v-if="empresas.length > 1" class="mt-2"><input v-model="nc.nombre" class="input !py-1" placeholder="Todo el grupo" data-e2e="nombre-conjunto" />
          <label v-for="e in empresas" :key="e.id" class="flex items-center gap-2 text-xs mt-1"><input v-model="nc.empresas" :value="e.id" type="checkbox" class="accent-carmin" /> {{ e.nombre }}</label>
          <button class="btn-secondary !py-1 text-xs mt-1" :disabled="!nc.nombre || nc.empresas.length < 2" @click="router.post('/conjuntos', nc)" data-e2e="crear-conjunto">Crear conjunto</button></div></div>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="e in empresas" :key="e.id" class="card" :class="e.actual ? 'border-violeta/40' : ''">
        <div class="flex items-start justify-between gap-2 mb-3">
          <div class="min-w-0"><p class="font-extrabold truncate"><span class="inline-block w-2.5 h-2.5 rounded-full mr-1.5" :class="{ rojo: 'bg-carmin', amarillo: 'bg-amber-400', verde: 'bg-emerald-500' }[e.semaforo.nivel]" data-e2e="semaforo"></span>{{ e.nombre }}</p><p class="text-xs text-marca-muted">{{ e.cuit ?? 'sin CUIT' }} · {{ e.condicion_iva }} · cierra en {{ meses[e.cierre_mes - 1] }}</p></div>
          <span v-if="e.actual" class="badge bg-lavanda-light text-violeta shrink-0">estás acá</span>
          <Link v-else :href="`/empresa/${e.id}`" method="post" as="button" class="btn-primary !py-1 text-xs shrink-0">Entrar</Link>
        </div>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div class="p-2.5 rounded-xl bg-marca-fondo"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Ventas</p><p class="font-extrabold tabular-nums">{{ moneda(e.ventas, 0) }}</p><p class="text-[11px] text-marca-muted">{{ e.ventas_n }} comprobantes</p></div>
          <div class="p-2.5 rounded-xl bg-marca-fondo"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Compras</p><p class="font-extrabold tabular-nums">{{ moneda(e.compras, 0) }}</p><p class="text-[11px] text-marca-muted">{{ e.compras_n }} comprobantes</p></div>
          <div class="p-2.5 rounded-xl col-span-2" :class="e.iva_saldo > 0 ? 'bg-carmin-light' : 'bg-emerald-50'"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">IVA del mes</p><p class="font-extrabold tabular-nums" :class="e.iva_saldo > 0 ? 'text-carmin' : 'text-emerald-700'">{{ e.iva_saldo > 0 ? 'A pagar ' : 'A favor ' }}{{ moneda(Math.abs(e.iva_saldo), 0) }}</p><p class="text-[11px] text-marca-muted">débito {{ moneda(e.iva_debito, 0) }} · crédito {{ moneda(e.iva_credito, 0) }}</p></div>
        </div>
        <ul v-if="e.semaforo.falta.length" class="mt-2 text-xs space-y-0.5"><li v-for="f in e.semaforo.falta" :key="f.texto" :class="f.nivel === 'rojo' ? 'text-carmin' : 'text-amber-700'">· {{ f.texto }}</li></ul>
        <div class="flex flex-wrap gap-1.5 mt-3 text-xs">
          <span class="badge" :class="e.sin_asiento ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">{{ e.sin_asiento }} sin asiento</span>
          <span class="badge" :class="e.pendientes_cae ? 'bg-carmin-light text-carmin' : 'bg-emerald-50 text-emerald-700'">{{ e.pendientes_cae }} pendientes de CAE</span>
          <span class="badge bg-marca-fondo text-marca-muted">copia {{ e.ultimo_backup ? new Date(e.ultimo_backup).toLocaleDateString('es-AR') : 'nunca' }}</span>
        </div>
        <div v-if="e.actual" class="flex flex-wrap gap-1.5 mt-3">
          <Link :href="`/contable/iva?desde=${periodo.desde}&hasta=${periodo.hasta}`" class="btn-secondary !py-1 text-xs">Libro IVA</Link>
          <Link :href="`/contable/contador?desde=${periodo.desde}&hasta=${periodo.hasta}`" class="btn-secondary !py-1 text-xs">Exportar asientos</Link>
          <Link :href="`/contable/fiscal?desde=${periodo.desde}&hasta=${periodo.hasta}`" class="btn-secondary !py-1 text-xs">Fiscal</Link>
        </div>
      </div>
    </div>
    <p v-if="empresas.length < 2" class="text-sm text-marca-muted mt-4">Tenés acceso a una sola empresa. Cuando otro cliente te dé acceso desde su panel del contador con este mismo email, aparece acá.</p>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ empresas: Array, mes: String, periodo: Object, estudio: Object, precio: Object, vencimientos: Array, conjuntos: Array })
const editar = ref(false)
const ef = reactive({ es_estudio: props.estudio.es_estudio, marca: props.estudio.marca, tramos: props.estudio.tramos.map(t => ({ ...t })), vencimientos: { ...props.estudio.vencimientos } })
const nc = reactive({ nombre: '', empresas: [] })
const meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
</script>
