<template>
  <AppLayout titulo="Configuración">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="grid lg:grid-cols-3 gap-4">
      <form @submit.prevent="form.post('/configuracion/empresa', { preserveScroll: true })" class="card lg:col-span-2 space-y-4">
        <h2 class="font-bold">Datos de la empresa</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="label">Nombre comercial</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
          <div><label class="label">Razón social</label><input v-model="form.razon_social" class="input" /></div>
          <div><label class="label">CUIT</label><input v-model="form.cuit" class="input" placeholder="30-12345678-9" /></div>
          <div><label class="label">Condición IVA</label>
            <select v-model="form.condicion_iva" class="input"><option>Responsable Inscripto</option><option>Monotributista</option><option>Exento</option><option>Consumidor Final</option></select></div>
          <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" /><p v-if="form.errors.email" class="text-carmin text-xs mt-1">{{ form.errors.email }}</p></div>
          <div><label class="label">Teléfono</label><input v-model="form.phone" class="input" /></div>
        </div>
        <h2 class="font-bold pt-2">Datos fiscales de la factura impresa</h2>
        <p class="text-xs text-marca-muted -mt-2">ARCA exige que la factura impresa muestre el domicilio comercial, los Ingresos Brutos y el inicio de actividades.</p>
        <div class="grid sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2"><label class="label">Domicilio comercial</label><input v-model="form.address" class="input" placeholder="Av. Colón 1234" data-domicilio /></div>
          <div><label class="label">Localidad</label><input v-model="form.city" class="input" /></div>
          <div><label class="label">Provincia</label><input v-model="form.province" class="input" /></div>
          <div><label class="label">Ingresos Brutos</label><input v-model="form.iibb" class="input" placeholder="Nº o Convenio Multilateral" data-iibb /></div>
          <div><label class="label">Inicio de actividades</label><input v-model="form.inicio_actividades" type="date" class="input" data-inicio /></div>
        </div>
        <h2 class="font-bold pt-2">AFIP</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="label">Punto de venta</label><input v-model="form.afip_punto_venta" class="input" placeholder="0001" /></div>
          <label class="flex items-center gap-2 text-sm mt-6"><input v-model="form.afip_produccion" type="checkbox" class="accent-carmin" /> Modo producción (desmarcado = homologación)</label>
        </div>
        <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing || !puedeEditar">{{ form.processing ? 'Guardando…' : 'Guardar cambios' }}</button></div>
      </form>

      <DisenoComprobantes class="lg:order-last" :marca="marca" :estilos="estilos" :empresa="empresa" />

      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-3">Plan actual</h2>
          <template v-if="plan">
            <div class="flex items-baseline gap-2"><span class="badge bg-violeta-light text-violeta">{{ plan.nombre }}</span><span class="text-xl font-black">$ {{ plan.precio.toLocaleString('es-AR') }}</span><span class="text-xs text-marca-muted">/mes</span></div>
            <p class="text-xs text-marca-muted mt-1">Vence el {{ plan.vence }}</p>
            <div class="mt-4 space-y-2 text-sm">
              <div class="flex justify-between"><span class="text-marca-muted">Usuarios</span><span class="font-semibold">{{ plan.usuarios[0] }} / {{ plan.usuarios[1] < 0 ? '∞' : plan.usuarios[1] }}</span></div>
              <div class="flex justify-between"><span class="text-marca-muted">Sucursales</span><span class="font-semibold">{{ plan.sucursales[0] }} / {{ plan.sucursales[1] < 0 ? '∞' : plan.sucursales[1] }}</span></div>
            </div>
          </template>
          <p v-else class="text-sm text-marca-muted">Sin suscripción activa.</p>
          <Link href="/suscripcion" class="btn-secondary w-full mt-4 !py-1.5 text-xs">Ver suscripción y renovar</Link>
        </div>
        <div class="card">
          <h2 class="font-bold mb-1">Verticales habilitados</h2>
          <p class="text-xs text-marca-muted mb-2">Además del rubro principal, podés prender otras pantallas: una ferretería con cabañas, un taller con local de venta.</p>
          <div class="space-y-1.5 text-sm">
            <label v-for="(lbl, k) in verticalesDisponibles" :key="k" class="flex items-center gap-2"><input type="checkbox" class="accent-carmin" :checked="vx.verticales_extra.includes(k)" @change="toggleVertical(k)" /> {{ lbl }}</label>
          </div>
          <button class="btn-secondary w-full mt-3 !py-1.5 text-xs" :disabled="vx.processing" @click="vx.post('/configuracion/empresa/verticales', { preserveScroll: true })">Guardar verticales</button>
        </div>
        <div class="card">
          <h2 class="font-bold mb-3">Módulos</h2>
          <div class="space-y-1.5">
            <div v-for="m in modulos" :key="m.key" class="flex items-center justify-between text-sm">
              <span :class="m.activo ? '' : 'text-marca-muted'">{{ m.label }}</span>
              <span class="badge" :class="m.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-marca-fondo text-marca-muted'">{{ m.activo ? 'Incluido' : 'No incluido' }}</span>
            </div>
          </div>
        </div>
        <div class="card">
          <h2 class="font-bold mb-1">Avisos al dueño por WhatsApp</h2>
          <p class="text-xs text-marca-muted mb-3">Todos los días a la hora que elijas te llega el resumen (ventas, cobros, caja, vencidos, pedidos web) y, cuando pasa algo crítico, un aviso al momento. {{ whatsappApi ? 'Se manda solo por la API.' : 'Sin API de WhatsApp, te deja el mensaje listo para mandar.' }}</p>
          <form @submit.prevent="av.post('/configuracion/empresa/avisos', { preserveScroll: true })" class="space-y-2 text-sm">
            <label class="flex items-center gap-2 font-semibold"><input v-model="av.activo" type="checkbox" class="accent-carmin" /> Activar avisos</label>
            <input v-model="av.whatsapp" class="input" placeholder="WhatsApp del dueño (549351…)" />
            <div class="grid grid-cols-2 gap-2"><div><label class="label">Hora del resumen</label><input v-model="av.hora" type="time" class="input" /></div><div class="flex flex-col justify-end gap-1"><label class="flex items-center gap-2"><input v-model="av.resumen_diario" type="checkbox" class="accent-carmin" /> Resumen diario</label><label class="flex items-center gap-2"><input v-model="av.criticas" type="checkbox" class="accent-carmin" /> Alertas críticas</label></div></div>
            <div class="flex gap-2"><button class="btn-primary flex-1" :disabled="av.processing">Guardar</button><button type="button" class="btn-secondary" @click="router.post('/configuracion/empresa/avisos/resumen', {}, { preserveScroll: true })">Ver resumen de hoy</button></div>
          </form>
          <pre v-if="resumenTexto" class="mt-3 text-xs whitespace-pre-wrap bg-marca-fondo rounded-xl p-3">{{ resumenTexto }}</pre>
        </div>
        <div class="card">
          <h2 class="font-bold mb-1">Tarjetas y planes de cuotas</h2>
          <p class="text-xs text-marca-muted mb-3">En el punto de venta, al cobrar con tarjeta se elige el plan y el recargo entra como ítem de la factura. Recargo negativo = descuento.</p>
          <div v-for="(t, ti) in tf.tarjetas" :key="ti" class="rounded-xl border border-marca-borde p-3 mb-2 text-sm">
            <div class="flex gap-2 items-center mb-2"><input v-model="t.nombre" class="input !py-1 flex-1" placeholder="Nombre de la tarjeta" /><button type="button" class="text-marca-muted hover:text-carmin text-xs" @click="tf.tarjetas.splice(ti, 1)">Quitar</button></div>
            <div class="flex flex-wrap gap-1.5">
              <span v-for="(pl, pi) in t.planes" :key="pi" class="inline-flex items-center gap-1 rounded-lg bg-marca-fondo px-2 py-1 text-xs"><input v-model.number="pl.cuotas" type="number" min="1" class="input !py-0.5 !px-1 w-12 text-center" /> cuotas <input v-model.number="pl.recargo" type="number" step="any" class="input !py-0.5 !px-1 w-16 text-right" />% <button type="button" class="text-marca-muted hover:text-carmin" @click="t.planes.splice(pi, 1)">✕</button></span>
              <button type="button" class="text-xs text-violeta font-semibold" @click="t.planes.push({ cuotas: (t.planes.at(-1)?.cuotas ?? 0) + 3, recargo: 0 })">+ plan</button>
            </div>
          </div>
          <div class="flex gap-2"><button type="button" class="btn-secondary flex-1 !py-1.5 text-xs" @click="tf.tarjetas.push({ nombre: '', planes: [{ cuotas: 1, recargo: 0 }] })">+ Tarjeta</button><button class="btn-primary flex-1 !py-1.5 text-xs" :disabled="tf.processing" @click="tf.post('/configuracion/empresa/tarjetas', { preserveScroll: true })">Guardar planes</button></div>
          <p v-if="Object.keys(tf.errors).length" class="text-carmin text-xs mt-1">Revisá nombres, cuotas (≥1) y recargos.</p>
        </div>
        <div class="card">
          <h2 class="font-bold mb-1">Mercado Pago: links, QR de mostrador y Point</h2>
          <p class="text-xs text-marca-muted mb-3">Con el access token se generan links de pago. Para cobrar con QR en el mostrador cargá tu user id y el id externo de la caja (POS) creada en Mercado Pago; para el lector Point, el id del dispositivo. {{ mercadopago.tiene_token ? 'Token cargado.' : 'Sin token: los links salen simulados.' }}</p>
          <form @submit.prevent="mp.post('/configuracion/empresa/mercadopago', { preserveScroll: true })" class="space-y-2 text-sm">
            <div><label class="label">Access token</label><input v-model="mp.access_token" class="input" placeholder="APP_USR-…" autocomplete="off" /></div>
            <div class="grid grid-cols-2 gap-2"><div><label class="label">User id (collector)</label><input v-model="mp.user_id" class="input" placeholder="123456789" /></div><div><label class="label">Caja QR (external id)</label><input v-model="mp.pos_external_id" class="input" placeholder="CAJA01" /></div></div>
            <div><label class="label">Point: id del dispositivo</label><input v-model="mp.point_device_id" class="input" placeholder="PAX_A910__SMARTPOS123…" /></div>
            <button class="btn-primary w-full" :disabled="mp.processing">Guardar Mercado Pago</button>
          </form>
        </div>
        <div class="card">
          <h2 class="font-bold mb-1">Punto de venta: balanza e impresora</h2>
          <form @submit.prevent="pf.post('/configuracion/empresa/pos', { preserveScroll: true })" class="space-y-2 text-sm">
            <p class="text-xs text-marca-muted">Balanza: códigos de peso variable (EAN-13 que empieza con el prefijo). El sistema lee el artículo y la cantidad o el importe.</p>
            <div class="grid grid-cols-3 gap-2"><div><label class="label">Prefijo</label><input v-model="pf.balanza_prefijo" class="input" maxlength="3" /></div><div><label class="label">Contiene</label><select v-model="pf.balanza_modo" class="input"><option value="peso">Peso (kg)</option><option value="importe">Importe ($)</option></select></div><div><label class="label">Decimales</label><input v-model.number="pf.balanza_decimales" type="number" min="0" max="3" class="input" /></div></div>
            <p class="text-xs text-marca-muted">Impresora térmica: por el navegador (ventana de impresión) o directo por cable/USB con WebSerial (Chrome/Edge), sin driver.</p>
            <div class="grid grid-cols-2 gap-2"><div><label class="label">Impresora</label><select v-model="pf.impresora" class="input"><option value="navegador">Ventana del navegador</option><option value="serial">Directa (WebSerial / ESC-POS)</option><option value="ninguna">No imprimir</option></select></div><div><label class="label">Ancho (caracteres)</label><select v-model.number="pf.ancho" class="input"><option :value="32">32 (58 mm)</option><option :value="42">42 (80 mm)</option><option :value="48">48 (80 mm chica)</option></select></div></div>
            <label class="flex items-center gap-2"><input v-model="pf.imprimir_auto" type="checkbox" class="accent-carmin" /> Imprimir el ticket automáticamente al cobrar</label>
            <button class="btn-primary w-full" :disabled="pf.processing">Guardar</button>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import DisenoComprobantes from '@/Components/DisenoComprobantes.vue'

const props = defineProps({ empresa: Object, plan: Object, modulos: Array, avisos: Object, pos: Object, resumenTexto: String, whatsappApi: Boolean, verticalesExtra: { type: Array, default: () => [] }, tarjetas: { type: Array, default: () => [] }, mercadopago: { type: Object, default: () => ({}) }, marca: { type: Object, default: () => ({ mostrar: {} }) }, estilos: { type: Object, default: () => ({}) } })
const tf = useForm({ tarjetas: JSON.parse(JSON.stringify(props.tarjetas)) })
const mp = useForm({ access_token: props.mercadopago.access_token ?? '', user_id: props.mercadopago.user_id ?? '', pos_external_id: props.mercadopago.pos_external_id ?? '', point_device_id: props.mercadopago.point_device_id ?? '' })
const verticalesDisponibles = { retail: 'Comercio / punto de venta', gastronomia: 'Gastronomía (mesas, comandas, cocina)', minimarket: 'Minimarket', servicios: 'Servicio técnico (órdenes de trabajo)', hoteleria: 'Hotelería (habitaciones, reservas, check-in)' }
const vx = useForm({ verticales_extra: [...props.verticalesExtra] })
const toggleVertical = k => { vx.verticales_extra = vx.verticales_extra.includes(k) ? vx.verticales_extra.filter(x => x !== k) : [...vx.verticales_extra, k] }
const av = useForm({ activo: !!props.avisos?.activo, whatsapp: props.avisos?.whatsapp ?? '', hora: props.avisos?.hora ?? '21:00', resumen_diario: props.avisos?.resumen_diario ?? true, criticas: props.avisos?.criticas ?? true })
const pf = useForm({ ...props.pos })
const form = useForm({ ...props.empresa })
const page = usePage()
const puedeEditar = computed(() => { const p = page.props.auth?.permisos ?? {}; return !!(p['*'] || p.configuracion?.includes('editar')) })
</script>
