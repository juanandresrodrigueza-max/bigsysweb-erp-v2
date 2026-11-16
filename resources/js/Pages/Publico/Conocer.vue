<template>
  <Head title="BigSys Web · ERP para pymes argentinas" />
  <div class="min-h-screen bg-marca-fondo text-marca-texto">
    <!-- Portada -->
    <header class="bg-ciruela-grad text-white relative overflow-hidden">
      <div class="pointer-events-none absolute -top-40 -left-40 w-[560px] h-[560px] rounded-full bg-rosa/30 blur-3xl"></div>
      <div class="pointer-events-none absolute -bottom-48 right-[-10%] w-[640px] h-[640px] rounded-full bg-violeta/40 blur-3xl"></div>
      <div class="relative mx-auto max-w-6xl px-6 py-6">
        <nav class="flex items-center justify-between gap-4">
          <Logo negativo clase="h-9" />
          <div class="flex items-center gap-2">
            <Link href="/login" class="rounded-full border border-white/25 bg-white/10 px-4 py-2 text-xs font-semibold hover:bg-white/20">Ingresar</Link>
            <Link href="/registro" class="btn-primary !py-2 text-xs" data-e2e="cta-arriba">Probar gratis</Link>
          </div>
        </nav>
        <div class="grid lg:grid-cols-[1.2fr_1fr] gap-10 items-center py-14 lg:py-20">
          <div>
            <p class="kicker !text-rosa-light/90">ERP 100 % web · Argentina · {{ dias }} días gratis</p>
            <h1 class="font-display mt-3 text-4xl sm:text-5xl lg:text-6xl leading-[1.02] font-light tracking-tight text-balance">Tu negocio entero, <span class="font-normal">y un asistente que trabaja de noche.</span></h1>
            <p class="mt-5 text-lg text-white/85 leading-relaxed max-w-xl">Vendé, facturá con ARCA, cobrá, controlá el stock, cerrá la caja y tené la contabilidad lista para el contador. Y Bigcito, que entiende voz, fotos y audios, te deja cada mañana lo que hay que hacer.</p>
            <div class="flex flex-wrap gap-3 mt-8">
              <Link href="/registro" class="btn-primary !px-6 !py-3" data-e2e="cta-hero">Crear mi cuenta gratis</Link>
              <a href="#planes" class="rounded-full border border-white/25 bg-white/10 px-6 py-3 text-sm font-semibold hover:bg-white/20">Ver planes</a>
            </div>
            <p class="mt-3 text-xs text-white/60">Sin tarjeta. Al terminar la prueba seguís gratis o elegís un plan.</p>
          </div>
          <div class="hidden lg:flex items-center gap-4 rounded-3xl bg-white/10 border border-white/15 p-6 backdrop-blur">
            <img src="/img/bigcito.webp" alt="Bigcito" class="w-28 h-28 rounded-2xl object-cover shadow-pop" loading="lazy" />
            <div class="text-sm space-y-2">
              <p class="font-semibold">«Buen día. Ayer vendiste 1.240.000 pesos, un 12 por ciento más que el martes. Te dejé tres propuestas: escribirle a López por 80.000 vencidos, pedirle a Distribuidora Norte y subir el precio del cemento.»</p>
              <p class="text-white/70">Bigcito, cada mañana, en tu celular.</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-6">
      <!-- Lo que hace -->
      <section class="py-14">
        <p class="kicker">Todo en un solo lugar</p>
        <h2 class="page-title !text-3xl mt-2">Lo que usás todos los días</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
          <div v-for="f in funciones" :key="f.t" class="card"><div class="flex items-center gap-3 mb-2"><span class="grid place-items-center w-10 h-10 rounded-xl bg-lavanda-light text-violeta"><Icono :nombre="f.i" clase="w-5 h-5" /></span><h3 class="font-bold">{{ f.t }}</h3></div><p class="text-sm text-marca-muted">{{ f.d }}</p></div>
        </div>
      </section>

      <!-- Bigcito de noche -->
      <section class="py-14 border-t border-marca-borde">
        <div class="grid lg:grid-cols-[1fr_1.4fr] gap-10 items-start">
          <div>
            <p class="kicker">Bigcito trabaja de noche</p>
            <h2 class="page-title !text-3xl mt-2">Vos aprobás. Él hace el resto.</h2>
            <p class="text-marca-muted mt-3">Cada madrugada cinco agentes revisan tu empresa y te dejan propuestas. Nada se ejecuta sin tu visto bueno, y queda registrado quién aprobó qué.</p>
            <img src="/img/bigcito-cara.webp" alt="" class="w-20 h-20 rounded-full mt-6 shadow-pop object-cover" loading="lazy" />
          </div>
          <ol class="space-y-3">
            <li v-for="(a, i) in agentes" :key="a.t" class="card flex gap-4"><span class="font-display text-3xl text-carmin font-light w-8 shrink-0">{{ i + 1 }}</span><div><h3 class="font-bold">{{ a.t }}</h3><p class="text-sm text-marca-muted">{{ a.d }}</p></div></li>
          </ol>
        </div>
      </section>

      <!-- Rubros -->
      <section class="py-14 border-t border-marca-borde">
        <p class="kicker">Para tu rubro</p>
        <h2 class="page-title !text-3xl mt-2">Bigcito te pregunta cómo trabajás y te deja el sistema a medida</h2>
        <div class="flex flex-wrap gap-2 mt-5"><span v-for="r in rubros" :key="r" class="badge bg-white border border-marca-borde text-sm !py-1.5 !px-3">{{ r }}</span></div>
      </section>

      <!-- Lo nuevo: sale solo de las novedades que publica cada fase -->
      <section v-if="novedades.length" id="lo-nuevo" class="py-14 border-t border-marca-borde scroll-mt-6" data-e2e="lo-nuevo">
        <p class="kicker">Lo nuevo</p>
        <h2 class="page-title !text-3xl mt-2">Mejora todas las semanas</h2>
        <p class="text-marca-muted mt-2">Esto es lo último que agregamos. Las empresas lo reciben solo, sin instalar nada.</p>
        <div class="grid md:grid-cols-2 gap-4 mt-6">
          <article v-for="n in novedades" :key="n.titulo" class="card"><p class="text-[11px] font-bold uppercase tracking-widest text-carmin">{{ n.fecha }}</p><h3 class="font-bold mt-1">{{ n.titulo }}</h3><p class="text-sm text-marca-muted mt-1 line-clamp-4">{{ n.texto }}</p></article>
        </div>
      </section>

      <!-- Planes -->
      <section id="planes" class="py-14 border-t border-marca-borde">
        <div class="flex flex-wrap items-end justify-between gap-3">
          <div><p class="kicker">Planes</p><h2 class="page-title !text-3xl mt-2">Empezá gratis, crecé cuando quieras</h2></div>
          <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1"><button v-for="c in [['mensual', 'Por mes'], ['anual', 'Por año (2 meses gratis)']]" :key="c[0]" @click="ciclo = c[0]" class="px-3 py-1 rounded-full text-xs font-semibold" :class="ciclo === c[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ c[1] }}</button></div>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
          <div v-for="p in planes" :key="p.slug" class="card flex flex-col" :class="p.recomendado ? 'ring-2 ring-carmin shadow-pop' : ''" :data-e2e="`plan-${p.slug}`">
            <div class="flex items-center justify-between"><h3 class="font-bold text-lg">{{ p.nombre }}</h3><span v-if="p.recomendado" class="badge bg-carmin text-white">El más elegido</span></div>
            <p class="text-sm text-marca-muted mt-1 min-h-10">{{ p.descripcion }}</p>
            <p class="mt-4"><span class="font-display text-3xl font-medium tabular-nums">{{ p.gratis ? 'Gratis' : moneda(ciclo === 'anual' ? p.anual / 12 : p.mensual, 0) }}</span><span v-if="!p.gratis" class="text-sm text-marca-muted"> / mes{{ ciclo === 'anual' ? ', pagando el año' : '' }}</span></p>
            <ul class="text-sm mt-4 space-y-1.5 flex-1">
              <li>✓ {{ p.usuarios < 0 ? 'Usuarios ilimitados' : p.usuarios + (p.usuarios === 1 ? ' usuario' : ' usuarios') }}</li>
              <li>✓ {{ p.sucursales < 0 ? 'Sucursales ilimitadas' : p.sucursales + (p.sucursales === 1 ? ' sucursal' : ' sucursales') }}</li>
              <li>✓ {{ p.facturas_mes < 0 ? 'Facturas ilimitadas' : p.facturas_mes.toLocaleString('es-AR') + ' facturas por mes' }}</li>
              <li>✓ {{ p.articulos < 0 ? 'Artículos ilimitados' : p.articulos.toLocaleString('es-AR') + ' artículos' }}</li>
              <li>✓ Factura electrónica con ARCA</li><li>✓ Bigcito y la app del celular</li>
            </ul>
            <Link href="/registro" class="mt-5 text-center" :class="p.recomendado ? 'btn-primary' : 'btn-secondary'">{{ p.gratis ? 'Empezar gratis' : `Probar ${dias} días gratis` }}</Link>
          </div>
        </div>
        <p class="text-xs text-marca-muted mt-4">Precios en pesos, IVA incluido. Se paga con Mercado Pago o transferencia desde el mismo sistema. Podés cambiar de plan o darte de baja cuando quieras.</p>
      </section>

      <!-- Cierre -->
      <section class="py-14 border-t border-marca-borde text-center">
        <h2 class="page-title !text-3xl">Probalo con tu negocio real</h2>
        <p class="text-marca-muted mt-2">En un minuto tenés la empresa armada. Si venís de otro sistema, importás clientes, artículos y saldos sin planillas.</p>
        <Link href="/registro" class="btn-primary !px-8 !py-3 mt-6 inline-flex" data-e2e="cta-final">Crear mi cuenta gratis</Link>
        <div v-if="tiendas.android || tiendas.ios" class="flex justify-center gap-3 mt-6 text-sm">
          <a v-if="tiendas.android" :href="tiendas.android" target="_blank" rel="noopener" class="btn-secondary">Google Play</a>
          <a v-if="tiendas.ios" :href="tiendas.ios" target="_blank" rel="noopener" class="btn-secondary">App Store</a>
        </div>
      </section>
    </main>

    <footer class="border-t border-marca-borde py-8 text-xs text-marca-muted">
      <div class="mx-auto max-w-6xl px-6 flex flex-wrap items-center justify-between gap-3">
        <span>BigSys Web · {{ new Date().getFullYear() }}</span>
        <span class="flex flex-wrap gap-4"><span v-if="soporte.whatsapp">WhatsApp <b class="select-all">{{ soporte.whatsapp }}</b></span><span v-if="soporte.email"><b class="select-all">{{ soporte.email }}</b></span><a href="/privacidad" class="underline">Privacidad</a><a href="/terminos" class="underline">Términos</a><Link href="/login" class="underline">Ingresar</Link></span>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
defineProps({ planes: Array, dias: Number, tiendas: Object, soporte: Object, rubros: Array, novedades: { type: Array, default: () => [] } })
const ciclo = ref('mensual')
const funciones = [
  { i: 'receipt', t: 'Vender y facturar con ARCA', d: 'Mostrador, presupuestos, facturas A, B y C con CAE, remitos, notas de crédito. La letra correcta para cada cliente, sola.' },
  { i: 'cash', t: 'Cobrar', d: 'Cuenta corriente, recordatorios por WhatsApp y mail, link de pago, Mercado Pago, cheques y planes de pago.' },
  { i: 'boxes', t: 'Stock y compras', d: 'Mínimos, qué reponer, órdenes de compra, lectura de facturas de proveedor con una foto, lotes y vencimientos.' },
  { i: 'wallet', t: 'Plata y caja', d: 'Cajas, bancos, billeteras, cierre de caja con diferencia, conciliación automática del extracto y de Mercado Pago.' },
  { i: 'book', t: 'Contabilidad lista', d: 'Asientos automáticos, libros de IVA, posición IVA, exportación a Tango, Holistor y Bejerman. El contador entra con su usuario.' },
  { i: 'chart', t: 'Estadísticas con criterio', d: 'Rentabilidad, márgenes a vigilar, lo raro del día, quién dejó de comprar, qué hacer esta semana.' },
]
const agentes = [
  { t: 'Cobrador', d: 'Mira quién debe, decide a quién escribirle según su historial y redacta el WhatsApp. Aprobás y sale.' },
  { t: 'Reposición', d: 'Arma la orden de compra sugerida por proveedor con lo que falta y la plata que hace falta.' },
  { t: 'Precios', d: 'Cuando sube el costo propone el precio nuevo respetando tu margen.' },
  { t: 'Cierre del mes', d: 'Comprobantes sin asiento, gastos sin categoría, cajas con diferencia: el contador sólo acepta.' },
  { t: 'Control interno', d: 'Si alguien del equipo se sale de lo normal, te lo cuenta con la evidencia.' },
]
</script>
