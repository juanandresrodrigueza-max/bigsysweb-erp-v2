<template>
  <AppLayout titulo="Centro de ayuda">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Centro de ayuda</h1><p class="page-subtitle">Guías cortas por pantalla, en criollo. Si no encontrás lo que buscás, Soporte está abajo.</p></div>
      <form @submit.prevent="router.get('/ayuda', { q: buscar }, { preserveState: true })" class="relative w-full sm:w-80"><Icono nombre="search" clase="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-marca-muted" /><input v-model="buscar" class="input !pl-9" placeholder="¿Qué querés hacer? ej. anular factura" /></form>
    </div>

    <div class="grid lg:grid-cols-4 gap-4">
      <div class="space-y-3">
        <div class="card p-2">
          <Link v-for="a in articulos" :key="a.slug" :href="`/ayuda/${a.slug}`" class="block px-3 py-2 rounded-xl text-sm hover:bg-marca-fondo" :class="articulo?.slug === a.slug ? 'bg-lavanda-light text-violeta font-semibold' : ''">{{ a.titulo }}</Link>
        </div>
        <div v-if="guias.length" class="card p-2">
          <p class="px-3 pt-1 pb-1 text-[10px] font-bold uppercase tracking-widest text-marca-muted">Guías técnicas</p>
          <Link v-for="g in guias" :key="g" :href="`/ayuda/guia/${g}`" class="block px-3 py-1.5 rounded-xl text-sm hover:bg-marca-fondo" :class="articulo?.slug === 'guia-' + g ? 'bg-lavanda-light text-violeta font-semibold' : ''">{{ { arca: 'ARCA y factura electrónica', asistente: 'Asistente con IA', atajos: 'Atajos de teclado', 'mercado-argentino': 'Percepciones, COT, cuotas, Mercado Pago', seguridad: 'Seguridad', api: 'API para desarrolladores' }[g] ?? g }}</Link>
          <a href="/api/docs" target="_blank" class="block px-3 py-1.5 rounded-xl text-sm hover:bg-marca-fondo">Referencia de la API ↗</a>
        </div>
        <div class="card text-sm">
          <p class="font-bold mb-1">¿No lo encontrás?</p>
          <p class="text-marca-muted text-xs mb-2">Abrí un ticket y te respondemos ahí y por mail.</p>
          <Link href="/soporte" class="btn-primary w-full !py-1.5 text-xs">Escribir a soporte</Link>
          <p v-if="soporte?.whatsapp" class="text-xs text-marca-muted mt-2">WhatsApp: <b class="select-all">{{ soporte.whatsapp }}</b></p>
          <button class="btn-ghost w-full !py-1 text-xs mt-1" @click="router.post('/ayuda/tour-reiniciar', {}, { preserveScroll: true })">Volver a ver el tour</button>
        </div>
      </div>

      <div class="lg:col-span-3">
        <div v-if="q" class="card mb-4">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Resultados para “{{ q }}”</p>
          <Link v-for="r in resultados" :key="r.slug" :href="`/ayuda/${r.slug}`" class="block py-2 border-t border-marca-borde/60 first:border-0 hover:text-carmin"><p class="font-semibold text-sm">{{ r.titulo }}</p><p class="text-xs text-marca-muted">…{{ r.fragmento }}…</p></Link>
          <p v-if="!resultados.length" class="text-sm text-marca-muted">Nada con esas palabras. Probá con otras o escribile a soporte.</p>
        </div>
        <article v-if="articulo" class="card">
          <h2 class="text-xl font-extrabold mb-1">{{ articulo.titulo }}</h2>
          <p v-if="articulo.resumen" class="text-sm text-marca-muted mb-3">{{ articulo.resumen }}</p>
          <div v-if="articulo.secciones?.length" class="flex flex-wrap gap-1.5 mb-4"><a v-for="s in articulo.secciones" :key="s.ancla" :href="'#' + s.ancla" class="badge bg-marca-fondo text-marca-muted hover:text-carmin" @click.prevent="irA(s.titulo)">{{ s.titulo }}</a></div>
          <div class="ayuda-prose" ref="cuerpo" v-html="articulo.html"></div>
        </article>
        <div v-else-if="!q" class="grid sm:grid-cols-2 gap-3">
          <Link v-for="a in articulos" :key="a.slug" :href="`/ayuda/${a.slug}`" class="card hover:border-carmin/50"><p class="font-bold">{{ a.titulo }}</p><p class="text-sm text-marca-muted mt-1">{{ a.resumen }}</p></Link>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
const props = defineProps({ articulos: Array, articulo: Object, q: String, resultados: Array, soporte: Object, guias: { type: Array, default: () => [] } })
const buscar = ref(props.q ?? ''), cuerpo = ref(null)
function irA(t) { const h = [...(cuerpo.value?.querySelectorAll('h2') ?? [])].find(x => x.textContent.trim() === t); h?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
</script>

<style>
.ayuda-prose { font-size: 14px; line-height: 1.6; }
.ayuda-prose h1 { font-size: 1.25rem; font-weight: 800; margin: 0 0 .5rem; }
.ayuda-prose h2 { font-size: 1rem; font-weight: 800; margin: 1.25rem 0 .4rem; color: #4f3089; }
.ayuda-prose h3 { font-weight: 700; margin: 1rem 0 .3rem; }
.ayuda-prose p { margin: .4rem 0; }
.ayuda-prose ul, .ayuda-prose ol { padding-left: 1.25rem; margin: .4rem 0; }
.ayuda-prose li { margin: .15rem 0; }
.ayuda-prose code { background: #f4f1f9; padding: 1px 5px; border-radius: 4px; font-size: .85em; }
.ayuda-prose pre { background: #f4f1f9; padding: 10px 12px; border-radius: 10px; overflow: auto; font-size: 12px; }
.ayuda-prose a { color: #e4003f; font-weight: 600; }
.ayuda-prose strong { font-weight: 700; }
</style>
