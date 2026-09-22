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
        <h2 class="font-bold pt-2">AFIP</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="label">Punto de venta</label><input v-model="form.afip_punto_venta" class="input" placeholder="0001" /></div>
          <label class="flex items-center gap-2 text-sm mt-6"><input v-model="form.afip_produccion" type="checkbox" class="accent-carmin" /> Modo producción (desmarcado = homologación)</label>
        </div>
        <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing || !puedeEditar">{{ form.processing ? 'Guardando…' : 'Guardar cambios' }}</button></div>
      </form>

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
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'

const props = defineProps({ empresa: Object, plan: Object, modulos: Array })
const form = useForm({ ...props.empresa })
const page = usePage()
const puedeEditar = computed(() => { const p = page.props.auth?.permisos ?? {}; return !!(p['*'] || p.configuracion?.includes('editar')) })
</script>
