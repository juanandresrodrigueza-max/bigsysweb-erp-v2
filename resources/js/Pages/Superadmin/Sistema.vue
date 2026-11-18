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
          <p>Laravel {{ version.laravel }} · PHP {{ version.php }} · base {{ version.db }}</p>
        </div>
        <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </div>

      <!-- Fase 93: la app nativa (iPhone y Android): Firebase para los avisos y los links a las tiendas. -->
      <!-- Fase 106: altas con aprobación (como en el CRM) -->
      <form id="aprobacion" @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3 lg:col-span-3" data-e2e="altas-aprobacion">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div><h2 class="font-bold">Altas con aprobación</h2><p class="text-xs text-marca-muted">Quien se registra (o la empresa que da de alta un empleado de BigSys) entra al toque, pero queda pendiente: si en estas horas nadie la aprueba con la clave en <Link href="/admin/pendientes" class="underline">Pendientes</Link>, se bloquea hasta que alguien lo haga. La clave se guarda como hash y no se vuelve a mostrar; nunca va por chat.</p></div>
          <span class="badge" :class="aprobacion.clave_set ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ aprobacion.clave_set ? 'Clave cargada' : 'Sin clave: no se puede aprobar' }}</span>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div><label class="label">Horas de acceso provisional</label><input v-model.number="form.horas_provisional" type="number" min="1" max="720" class="input" data-e2e="horas-provisional" /></div>
          <div v-if="aprobacion.puede_cambiar"><label class="label">{{ aprobacion.clave_set ? 'Clave nueva (vacío = no cambiarla)' : 'Clave de aprobación' }}</label><input v-model="form.clave_aprobacion" type="password" class="input" autocomplete="new-password" minlength="6" data-e2e="clave-nueva" />
            <input v-if="aprobacion.clave_set" v-model="form.clave_actual" type="password" class="input mt-2" autocomplete="off" placeholder="Clave actual (obligatoria para cambiarla)" data-e2e="clave-actual" />
            <p v-if="form.errors.clave_aprobacion" class="text-xs text-carmin mt-1" data-e2e="error-clave-nueva">{{ form.errors.clave_aprobacion }}</p></div>
          <div v-else class="p-3 rounded-xl bg-marca-fondo text-xs" data-e2e="clave-ajena"><p class="font-semibold text-marca-texto">La clave la administran</p><p>{{ aprobacion.nombres_duenos }}. Vos podés usarla para aprobar, no cambiarla.</p></div>
          <div><label class="label">Avisar por mail a (separados por coma)</label><input v-model="form.aviso_registros_emails" class="input" placeholder="vacío = superadmins + soporte" /></div>
          <div><label class="label">Avisar por WhatsApp a (celulares, con 549…)</label><input v-model="form.aviso_registros_whatsapp" class="input" placeholder="5493515550000" /><p class="text-[11px] text-marca-muted mt-1">Sale por la línea de WhatsApp de la empresa de BigSys.</p></div>
        </div>
        <!-- Fase 113.2: por dónde sale el aviso de cada alta, y una prueba real -->
        <div class="rounded-xl bg-marca-fondo p-3 text-xs space-y-1" data-e2e="canales-aviso">
          <p class="font-semibold text-marca-texto">Cuando alguien se registra, el aviso sale por:</p>
          <p v-for="(c, k) in aprobacion.canales" :key="k"><span :class="c.ok ? 'text-emerald-700' : 'text-carmin'">{{ c.ok ? '✓' : '✗' }}</span> <b>{{ { mail: 'Mail', whatsapp: 'WhatsApp', celular: 'Celular' }[k] }}:</b> {{ c.texto }}</p>
          <p v-if="prueba?.que === 'aviso'" class="whitespace-pre-line mt-2 px-3 py-2 rounded-lg" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin-dark'" data-e2e="prueba-aviso">{{ prueba.texto }}</p>
          <div class="pt-1"><Link href="/admin/sistema/probar-aviso" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs" data-e2e="probar-aviso">Enviar aviso de prueba por los tres canales</Link></div>
        </div>
        <div class="flex items-center justify-between"><p class="text-xs text-marca-muted">Pendientes ahora: <b>{{ aprobacion.pendientes }}</b>.</p><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
        <!-- Quiénes pueden cambiar la clave: lo decide el dueño (quien la cargó primero y a quienes designe) -->
        <div v-if="aprobacion.clave_set" class="border-t border-marca-borde pt-3" data-e2e="clave-duenos">
          <p class="text-sm font-semibold">Quiénes pueden cambiar la clave y designar a otros</p>
          <p class="text-xs text-marca-muted mb-2">Hoy: {{ aprobacion.nombres_duenos }}. Cualquier superadmin que sepa la clave puede aprobar; cambiarla, sólo estas personas. Si se pierde, se resetea desde el servidor (<code>php artisan aprobacion:reset-clave</code>).</p>
          <div v-if="aprobacion.es_dueno" class="flex flex-wrap gap-3 items-center">
            <label v-for="u in aprobacion.superadmins" :key="u.id" class="flex items-center gap-1.5 text-sm" :title="u.email"><input type="checkbox" class="accent-carmin" :value="u.id" v-model="duenos.ids" :disabled="u.id === $page.props.auth.user.id" /> {{ u.name }}</label>
            <button type="button" class="btn-secondary !py-1 text-xs" :disabled="duenos.processing" @click="duenos.post('/admin/sistema/clave-duenos', { preserveScroll: true })" data-e2e="guardar-duenos">Guardar quiénes</button>
          </div>
        </div>
      </form>
      <!-- Fase 96: entrar con Google -->
      <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3 lg:col-span-3" data-e2e="google-login">
        <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="font-bold">Entrar con Google</h2><p class="text-xs text-marca-muted">Un botón «Entrar con Google» en el login para todas las empresas. Sólo entra quien ya tiene usuario con ese mail; no crea cuentas.</p></div><span class="badge" :class="google.client_id && google.secret_set ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ google.client_id && google.secret_set ? 'Activo' : 'Sin configurar' }}</span></div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Client ID</label><input v-model="form.google_client_id" class="input" placeholder="xxxx.apps.googleusercontent.com" /></div>
          <div><label class="label">Client secret {{ google.secret_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.google_client_secret" type="password" class="input" autocomplete="off" /></div>
        </div>
        <p class="text-[11px] text-marca-muted">En Google Cloud → APIs y servicios → Credenciales → ID de cliente OAuth (aplicación web). URI de redirección autorizada: <code class="select-all">{{ google.volver }}</code></p>
        <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </form>
      <div class="card space-y-4 lg:col-span-3" data-e2e="app-movil">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div><h2 class="font-bold">App del celular (iPhone y Android)</h2><p class="text-xs text-marca-muted">Los avisos de la app salen por Firebase Cloud Messaging; en iPhone, Firebase los entrega por APNs. Una sola cuenta para todas las empresas.</p></div>
          <span class="badge" :class="movil.fcm_set ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ movil.fcm_set ? 'Firebase cargado · ' + movil.fcm_proyecto : 'Sin Firebase: la app anda, pero sin avisos' }}</span>
        </div>
        <div class="grid lg:grid-cols-3 gap-4">
          <div class="lg:col-span-2"><label class="label">Cuenta de servicio de Firebase (JSON) {{ movil.fcm_set ? '· dejar vacío para no cambiarla' : '' }}</label><textarea v-model="form.fcm_service_account" rows="4" class="input font-mono !text-xs" placeholder='{"type": "service_account", "project_id": "...", "client_email": "...", "private_key": "-----BEGIN PRIVATE KEY-----..."}' autocomplete="off"></textarea><p class="text-xs text-marca-muted mt-1">Firebase → Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada. Queda cifrada; nunca la pegues en un chat.</p><p v-if="form.errors.fcm_service_account" class="text-xs text-carmin mt-1">{{ form.errors.fcm_service_account }}</p></div>
          <div class="space-y-3">
            <div><label class="label">Link en Google Play</label><input v-model="form.app_android_url" class="input" placeholder="https://play.google.com/store/apps/details?id=ar.com.bigsys.erp" /></div>
            <div><label class="label">Link en App Store</label><input v-model="form.app_ios_url" class="input" placeholder="https://apps.apple.com/ar/app/bigsys/id…" /></div>
            <div class="grid grid-cols-2 gap-2"><div><label class="label">Huella SHA-256 (Android)</label><input v-model="form.android_sha256" class="input !text-xs font-mono" placeholder="AA:BB:…" title="Play Console → Integridad de la app → huella SHA-256. Con esto los links abren la app." /></div><div><label class="label">Team ID (Apple)</label><input v-model="form.apple_team_id" class="input font-mono" maxlength="10" placeholder="ABCDE12345" /></div></div>
            <div class="p-3 rounded-xl bg-marca-fondo text-xs text-marca-muted">
              <p class="font-semibold text-marca-texto">Celulares con la app</p>
              <p>iPhone: <b>{{ movil.dispositivos?.ios ?? 0 }}</b> · Android: <b>{{ movil.dispositivos?.android ?? 0 }}</b></p>
              <Link href="/admin/sistema/probar-push" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs mt-2" data-e2e="probar-push">Mandarme un aviso de prueba</Link>
            </div>
          </div>
        </div>
      </div>
    </form>

    <!-- Fase 87: la empresa propia de BigSys. Los superadmins la usan como su empresa y saltan entre el panel y ella. -->
    <div class="card space-y-3 mt-4" data-e2e="empresa-bigsys">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-bold">La empresa de BigSys en el ERP</h2><span class="badge" :class="bigsys ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ bigsys ? bigsys.nombre : 'Sin definir' }}</span></div>
      <p class="text-sm text-marca-muted">Como en el CRM: los superadmins tienen su propia empresa para la gestión de BigSys (facturar, cobrar, la plata) y saltan entre el panel y la empresa con un botón, sin el modo soporte. Todos los superadmins, los de ahora y los que se creen después, entran como dueños.</p>
      <div v-if="bigsys" class="flex flex-wrap gap-2"><Link href="/dashboard" class="btn-primary"><Icono nombre="building" clase="w-4 h-4" /> Entrar a {{ bigsys.nombre }}</Link></div>
      <form v-else class="grid sm:grid-cols-[1fr_auto_1fr_auto] gap-2 items-end" @submit.prevent="eb.post('/admin/sistema/empresa-bigsys', { preserveScroll: true })">
        <div><label class="label">Crear una nueva</label><input v-model="eb.nombre" class="input" placeholder="BigSysWeb" /></div>
        <button class="btn-primary" :disabled="eb.processing" @click.prevent="eb.business_id = null; eb.post('/admin/sistema/empresa-bigsys', { preserveScroll: true })" data-e2e="crear-bigsys">Crear</button>
        <div><label class="label">O usar una que ya existe</label><select v-model="eb.business_id" class="input"><option :value="null">Elegí la empresa…</option><option v-for="e in empresasParaBigsys" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
        <button class="btn-secondary" :disabled="eb.processing || !eb.business_id" data-e2e="usar-bigsys">Usar esta</button>
      </form>
    </div>

    <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3 mt-4" data-e2e="apps-tiendas">
      <h2 class="font-bold">Aplicaciones para conectar tiendas con un botón</h2>
      <p class="text-sm text-marca-muted">Una sola vez para todos los clientes: la aplicación de BigSys en MercadoLibre (developers.mercadolibre.com.ar) y en Tienda Nube (partners.tiendanube.com). Con esto cada empresa conecta su cuenta entrando con su usuario, sin copiar claves. La URL de retorno de las dos es <code>{{ base }}/conectar/&lt;tienda&gt;/volver</code> y la de notificaciones de MercadoLibre <code>{{ base }}/api/canales/mercadolibre/notificaciones</code>.</p>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Mercado Pago (cobros en el local) · App ID</label><input v-model="form.mp_app_id" class="input" /></div>
        <div><label class="label">Mercado Pago · Secret {{ config.mp_app_secret_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.mp_app_secret" type="password" class="input" autocomplete="new-password" /></div>
        <div><label class="label">MercadoLibre · App ID</label><input v-model="form.ml_app_id" class="input" /></div>
        <div><label class="label">MercadoLibre · Secret {{ config.ml_app_secret_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.ml_app_secret" type="password" class="input" autocomplete="off" /></div>
        <div><label class="label">Tienda Nube · App ID</label><input v-model="form.tn_app_id" class="input" /></div>
        <div><label class="label">Tienda Nube · Secret {{ config.tn_app_secret_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.tn_app_secret" type="password" class="input" autocomplete="off" /></div>
      </div>
      <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
    </form>

    <!-- Fase 118: WhatsApp con el QR de Meta -->
    <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3 mt-4" data-e2e="app-meta">
      <h2 class="font-bold">WhatsApp con un botón (aplicación de Meta)</h2>
      <p class="text-sm text-marca-muted">La misma aplicación de Meta que usa el CRM. Con esto cada empresa conecta su WhatsApp escaneando el QR desde la app WhatsApp Business (coexistencia: el número sigue andando en el celular), sin copiar Phone ID ni tokens. En la app de Meta, <code>{{ host }}</code> tiene que estar en «Dominios de la app» y en «Dominios permitidos para el SDK de JavaScript». Cada número conectado desde acá suscribe su propia URL de avisos <code>{{ base }}/api/whatsapp/entrante/&lt;empresa&gt;</code>, así no se pisa con el CRM.</p>
      <div class="p-3 rounded-xl text-sm" :class="config.meta_app_id && config.meta_app_secret_set && config.meta_config_id ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">
        <b>Estado:</b> {{ config.meta_app_id && config.meta_app_secret_set && config.meta_config_id ? 'lista. Las empresas ven el botón «Conectar WhatsApp».' : 'incompleta. Las empresas sólo pueden pegar Phone ID y token a mano.' }}
      </div>
      <div class="grid sm:grid-cols-3 gap-3">
        <div><label class="label">App ID</label><input v-model="form.meta_app_id" class="input" /></div>
        <div><label class="label">App Secret {{ config.meta_app_secret_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.meta_app_secret" type="password" class="input" autocomplete="new-password" /></div>
        <div><label class="label">Configuration ID (Embedded Signup)</label><input v-model="form.meta_config_id" class="input" /></div>
      </div>
      <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
    </form>

    <div class="grid lg:grid-cols-2 gap-4 mt-4">
      <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-bold">Inteligencia artificial (Claude)</h2><span class="badge" :class="ia ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ ia ? (config.ia_api_key_set ? 'clave del panel' : 'clave del .env') : 'modo básico' }}</span></div>
        <p class="text-sm text-marca-muted">Con clave, el asistente, el analista, la lectura de facturas y la interpretación de pedidos usan IA. Sin clave, todo sigue funcionando en modo básico. La clave se guarda cifrada y no se vuelve a mostrar.</p>
        <div><label class="label">API key {{ config.ia_api_key_set ? '(dejar vacío para no cambiarla)' : '' }}</label><input v-model="form.ia_api_key" type="password" class="input" autocomplete="off" placeholder="sk-ant-…" /></div>
        <div><label class="label">Modelo</label><input v-model="form.ia_modelo" class="input" placeholder="claude-sonnet-5" /><p class="text-[11px] text-marca-muted mt-1">Haiku es más barato y rápido; Sonnet entiende mejor pedidos complejos y facturas borrosas.</p></div>
        <p v-if="prueba?.que === 'ia'" class="text-sm px-3 py-2 rounded-lg mb-2" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin-dark'" data-e2e="prueba-ia">{{ prueba.ok ? '✓' : '✗' }} {{ prueba.texto }}</p>
        <div class="flex flex-wrap gap-2 justify-end"><button v-if="config.ia_api_key_set" type="button" class="btn-ghost text-xs text-carmin" @click="borrar('ia_api_key')">Borrar clave del panel</button><Link href="/admin/sistema/probar-ia" method="post" as="button" preserve-scroll class="btn-secondary">Probar la clave</Link><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </form>
      <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-bold">Correo saliente (SMTP)</h2><span class="badge" :class="config.mail_activo === 'smtp' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ config.mail_activo === 'smtp' ? (config.mail_host ? 'SMTP del panel' : 'SMTP del .env') : `sin envío real (${config.mail_activo})` }}</span></div>
        <p class="text-sm text-marca-muted">Para recuperar contraseñas, mandar comprobantes, recordatorios y avisos. Cualquier casilla con SMTP sirve (Gmail con clave de aplicación, el hosting, Resend, Brevo…).</p>
        <div class="grid grid-cols-3 gap-2"><div class="col-span-2"><label class="label">Servidor</label><input v-model="form.mail_host" class="input" placeholder="smtp.gmail.com" /></div><div><label class="label">Puerto</label><input v-model.number="form.mail_port" type="number" class="input" /></div></div>
        <div class="grid grid-cols-2 gap-2"><div><label class="label">Usuario</label><input v-model="form.mail_username" class="input" autocomplete="off" /></div><div><label class="label">Contraseña {{ config.mail_password_set ? '(vacío = no cambiar)' : '' }}</label><input v-model="form.mail_password" type="password" class="input" autocomplete="off" /></div></div>
        <div class="grid grid-cols-3 gap-2"><div><label class="label">Cifrado</label><select v-model="form.mail_encryption" class="input"><option value="tls">TLS (587)</option><option value="ssl">SSL (465)</option><option value="none">Ninguno</option></select></div><div><label class="label">Remitente</label><input v-model="form.mail_from_address" type="email" class="input" placeholder="no-responder@bigsys.com.ar" /></div><div><label class="label">Nombre</label><input v-model="form.mail_from_name" class="input" /></div></div>
        <p v-if="prueba?.que === 'correo'" class="text-sm px-3 py-2 rounded-lg mb-2" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin-dark'" data-e2e="prueba-correo">{{ prueba.ok ? '✓' : '✗' }} {{ prueba.texto }}</p>
        <div class="flex flex-wrap items-center gap-2 justify-end"><input v-model="pruebaA" type="email" class="input !w-56 !py-1.5 text-sm" placeholder="Mandar prueba a…" /><button type="button" class="btn-secondary" :disabled="!pruebaA" @click="router.post('/admin/sistema/probar-correo', { a: pruebaA }, { preserveScroll: true })">Enviar prueba</button><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </form>
    </div>
    <div class="card mt-4" :class="mt.activo ? 'border-carmin' : ''">
      <h2 class="font-bold mb-1">Modo mantenimiento</h2>
      <p class="text-sm text-marca-muted mb-3">Mientras está activo, todos los usuarios ven una pantalla de aviso (los superadmin siguen entrando). Usalo para migraciones o cambios grandes.</p>
      <form @submit.prevent="mt.post('/admin/sistema/mantenimiento', { preserveScroll: true })" class="grid sm:grid-cols-3 gap-3 items-end">
        <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="mt.activo" type="checkbox" class="accent-carmin" /> Activar mantenimiento</label>
        <div><label class="label">Mensaje</label><input v-model="mt.mensaje" class="input" placeholder="Estamos haciendo mejoras…" /></div>
        <div><label class="label">Volvemos aprox.</label><input v-model="mt.hasta" class="input" placeholder="Ej. 22:30" /></div>
        <div class="sm:col-span-3 flex justify-end"><button class="btn-primary" :disabled="mt.processing">{{ mt.activo ? 'Guardar y activar' : 'Guardar' }}</button></div>
      </form>
    </div>
    <div class="grid lg:grid-cols-2 gap-4 mt-4">
      <div class="card" data-e2e="publicar-novedad">
        <p class="font-bold mb-2">Publicar una novedad (campanita)</p>
        <form class="space-y-2" @submit.prevent="nov.post('/admin/novedades', { preserveScroll: true, onSuccess: () => nov.reset() })">
          <input v-model="nov.titulo" class="input" placeholder="Título" />
          <textarea v-model="nov.texto" rows="3" class="input" placeholder="Qué trae y para qué sirve"></textarea>
          <div class="grid grid-cols-2 gap-2"><input v-model="nov.url" class="input" placeholder="Enlace (ej. /mejoras)" /><input v-model="nov.modulo" class="input" placeholder="Sólo a quien tiene el módulo (opcional)" /></div>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="nov.push" /> Mandarla también al celular</label>
          <button class="btn-primary" :disabled="nov.processing || !nov.titulo || !nov.texto">Publicar</button>
        </form>
        <p v-for="n in novedades" :key="n.id" class="text-xs text-marca-muted mt-1">{{ n.fecha }} · {{ n.titulo }}{{ n.modulo ? ' · ' + n.modulo : '' }}</p>
      </div>
      <div class="card">
        <p class="font-bold mb-2">“Me interesa” de las empresas</p>
        <p v-if="!intereses.length" class="text-sm text-marca-muted">Nadie pidió información todavía.</p>
        <p v-for="(i, k) in intereses" :key="k" class="text-sm py-1 border-t border-marca-borde/60 first:border-0"><b>{{ i.empresa }}</b> quiere {{ i.modulo }} <span class="text-xs text-marca-muted">· {{ i.fecha }}</span><span v-if="i.comentario" class="block text-xs text-marca-muted">“{{ i.comentario }}”</span></p>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Icono from '@/Components/Icono.vue'
const props = defineProps({ aprobacion: { type: Object, default: () => ({}) }, google: { type: Object, default: () => ({}) }, movil: { type: Object, default: () => ({}) }, bigsys: Object, empresasParaBigsys: { type: Array, default: () => [] }, config: Object, ia: Boolean, version: Object, mantenimiento: Object, novedades: { type: Array, default: () => [] }, intereses: { type: Array, default: () => [] } })
const eb = useForm({ nombre: '', business_id: null })
const nov = useForm({ titulo: '', texto: '', url: '', modulo: '', push: false })
const mt = useForm({ activo: !!props.mantenimiento?.activo, mensaje: props.mantenimiento?.mensaje ?? '', hasta: props.mantenimiento?.hasta ?? '' })
const base = location.origin
const host = location.host
const duenos = useForm({ ids: [...(props.aprobacion.duenos ?? [])] }) // Fase 106
const form = useForm({ ...props.config, horas_provisional: props.aprobacion.horas ?? 72, clave_aprobacion: '', aviso_registros_emails: props.aprobacion.emails ?? '', aviso_registros_whatsapp: props.aprobacion.whatsapp ?? '', clave_actual: '', mp_access_token: '', ia_api_key: '', mail_password: '', ml_app_secret: '', tn_app_secret: '', mp_app_secret: '', meta_app_secret: '', meta_app_id: props.config.meta_app_id ?? '', meta_config_id: props.config.meta_config_id ?? '', mail_encryption: props.config.mail_encryption || 'tls', fcm_service_account: '', google_client_id: props.google?.client_id ?? '', google_client_secret: '', app_android_url: props.movil?.app_android_url ?? '', app_ios_url: props.movil?.app_ios_url ?? '', android_sha256: props.movil?.android_sha256 ?? '', apple_team_id: props.movil?.apple_team_id ?? '' })
const pruebaA = ref('')
const prueba = computed(() => usePage().props.flash?.prueba) // resultado de "Probar", dentro de su tarjeta
function borrar(clave) { router.post('/admin/sistema/clave/borrar', { clave }, { preserveScroll: true }) }
</script>
