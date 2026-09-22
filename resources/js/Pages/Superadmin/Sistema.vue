<template>
  <AdminLayout titulo="Sistema">
    <div class="mb-5"><h1 class="page-title">Parámetros del sistema</h1><p class="page-subtitle">Reglas de prueba y vencimiento, cómo cobra BigSys y datos de soporte.</p></div>

    <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="grid lg:grid-cols-3 gap-4">
      <div class="card space-y-4">
        <h2 class="font-bold">Ciclo de suscripción</h2>
        <div><label class="label">Días de prueba gratis</label><input v-model.number="form.dias_prueba" type="number" min="1" class="input" /></div>
        <div><label class="label">Días de gracia después del vencimiento</label><input v-model.number="form.dias_gracia" type="number" min="0" class="input" /><p class="text-xs text-marca-muted mt-1">Vencida la gracia, la empresa queda suspendida hasta que pague.</p></div>
        <div><label class="label">Avisar cuando falten (días, separados por coma)</label><input v-model="form.aviso_dias" class="input" placeholder="7,3,1" /></div>
        <div class="p-3 rounded-xl bg-marca-fondo text-sm">
          <p class="font-semibold mb-1">Revisión automática</p>
          <p class="text-marca-muted text-xs">Corre todos los días a las 06:00. Podés forzarla ahora.</p>
          <Link href="/admin/sistema/revisar" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs mt-2">Revisar vencimientos ahora</Link>
        </div>
      </div>

      <div class="card space-y-4">
        <h2 class="font-bold">Cobros de BigSys</h2>
        <div class="p-3 rounded-xl text-sm" :class="config.mp_access_token_set ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">
          <b>MercadoPago:</b> {{ config.mp_access_token_set ? 'credenciales cargadas, los pagos van en serio.' : 'sin credenciales. Los pagos corren en modo simulado (se aprueban solos) para probar el circuito.' }}
        </div>
        <div><label class="label">Public key</label><input v-model="form.mp_public_key" class="input" placeholder="APP_USR-…" /></div>
        <div><label class="label">Access token {{ config.mp_access_token_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.mp_access_token" type="password" class="input" autocomplete="off" placeholder="APP_USR-…" /></div>
        <p class="label !mb-0 pt-2">Transferencia bancaria</p>
        <div><label class="label">Titular</label><input v-model="form.transferencia_titular" class="input" /></div>
        <div class="grid grid-cols-2 gap-3"><div><label class="label">CBU</label><input v-model="form.transferencia_cbu" class="input tabular-nums" /></div><div><label class="label">Alias</label><input v-model="form.transferencia_alias" class="input" /></div></div>
      </div>

      <div class="card space-y-4">
        <h2 class="font-bold">Soporte y avisos</h2>
        <div><label class="label">WhatsApp de soporte</label><input v-model="form.soporte_whatsapp" class="input" /></div>
        <div><label class="label">Email de soporte</label><input v-model="form.soporte_email" type="email" class="input" /></div>
        <div><label class="label">Mensaje global (lo ven todas las empresas arriba de la pantalla)</label><textarea v-model="form.mensaje_global" rows="3" class="input" placeholder="Ej: El sábado de 2 a 4 hay mantenimiento."></textarea></div>
        <div class="text-xs text-marca-muted space-y-1 pt-2 border-t border-marca-borde">
          <p>Asistente IA: <b :class="ia ? 'text-emerald-700' : 'text-amber-700'">{{ ia ? 'con Claude' : 'modo básico (sin ANTHROPIC_API_KEY)' }}</b></p>
          <p>Laravel {{ version.laravel }} · PHP {{ version.php }} · base {{ version.db }}</p>
        </div>
        <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </div>
    </form>
  </AdminLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
const props = defineProps({ config: Object, ia: Boolean, version: Object })
const form = useForm({ ...props.config, mp_access_token: '' })
</script>
