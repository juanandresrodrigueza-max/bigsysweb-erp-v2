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
      <p class="text-sm text-marca-muted">Una sola vez para todos los clientes: la aplicación de BigSys en cada plataforma. Con esto cada empresa conecta su cuenta entrando con su usuario, sin copiar claves. Estas son las direcciones que piden al crear cada aplicación (la guía paso a paso está en la ayuda, «Panel de BigSys»):</p>
      <ul class="text-sm space-y-1" data-e2e="apps-urls">
        <li><b>Mercado Pago</b> (developers.mercadopago.com.ar) · URL de redirección <code class="select-all">{{ base }}/conectar/mercadopago/volver</code> · notificaciones <code class="select-all">{{ base }}/api/mercadopago/webhook</code></li>
        <li><b>MercadoLibre</b> (developers.mercadolibre.com.ar) · URI de redirect <code class="select-all">{{ base }}/conectar/mercadolibre/volver</code> · notificaciones <code class="select-all">{{ base }}/api/canales/mercadolibre/notificaciones</code> (tema «Orders v2»)</li>
        <li><b>Tienda Nube</b> (partners.tiendanube.com) · URL de redirección <code class="select-all">{{ base }}/conectar/tiendanube/volver</code> · datos personales: <code class="select-all">{{ base }}/api/tiendanube/lgpd/store-redact</code>, <code class="select-all">{{ base }}/api/tiendanube/lgpd/customers-redact</code> y <code class="select-all">{{ base }}/api/tiendanube/lgpd/customers-data-request</code></li>
      </ul>
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

    <!-- Fase 119.2: voz de Bigcito para todas las empresas -->
    <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3 mt-4" data-e2e="voz-bigcito">
      <h2 class="font-bold">Voz de Bigcito (para todas las empresas)</h2>
      <p class="text-sm text-marca-muted">Con una clave de OpenAI o de Gemini, Bigcito <b>escucha</b> en cualquier navegador y celular (iPhone, Safari, Firefox, audios de WhatsApp) y <b>contesta con voz natural</b>. Vale para todas las empresas que no cargaron su propia clave en Configuración → Conexiones IA. Sin clave, sólo dicta donde el navegador lo permite (Chrome, Edge, Android) y habla con la voz del navegador.</p>
      <div class="p-3 rounded-xl text-sm" :class="config.voz_api_key_set ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">
        <b>Estado:</b> {{ config.voz_api_key_set ? 'activa. Bigcito escucha y habla por servidor.' : 'sin clave. Voz sólo del navegador.' }}
      </div>
      <div class="grid sm:grid-cols-3 gap-3">
        <div><label class="label">Servicio</label><select v-model="form.voz_proveedor" class="input"><option value="openai">OpenAI</option><option value="gemini">Google Gemini</option></select></div>
        <div><label class="label">Clave {{ config.voz_api_key_set ? '(dejar vacío para no cambiarla)' : '' }}</label><input v-model="form.voz_api_key" type="password" class="input font-mono" autocomplete="new-password" placeholder="sk-… o AIza…" /></div>
        <div><label class="label">Voz</label><select v-model="form.voz_nombre" class="input"><option :value="null">La de siempre</option><option v-for="v in (config.voces?.[form.voz_proveedor] ?? [])" :key="v" :value="v">{{ v }}</option></select></div>
      </div>
      <!-- Fase 120.4: conversación en vivo -->
      <div class="rounded-xl border border-marca-borde p-3 space-y-2" data-e2e="voz-vivo">
        <label class="flex items-start gap-2 text-sm"><input v-model="form.voz_vivo" type="checkbox" class="mt-1" :disabled="form.voz_proveedor !== 'openai'" /><span><b>Conversación en vivo (📞)</b>: hablar con Bigcito como por teléfono, se lo puede interrumpir y contesta al instante. Usa el servicio de voz en tiempo real de OpenAI (sólo con clave de OpenAI) y <b>se cobra por minuto hablado</b>, bastante más caro que el micrófono común; por eso tiene tope por llamada y por día. <span v-if="form.voz_proveedor !== 'openai'" class="text-carmin">Elegí OpenAI como servicio para poder prenderla.</span></span></label>
        <div v-if="form.voz_vivo" class="grid sm:grid-cols-3 gap-3">
          <div><label class="label">Modelo</label><input v-model="form.voz_vivo_modelo" class="input font-mono" placeholder="gpt-realtime" /></div>
          <div><label class="label">Minutos máximos por llamada</label><input v-model.number="form.voz_vivo_minutos" type="number" min="1" max="30" class="input" /></div>
          <div><label class="label">Llamadas por usuario por día</label><input v-model.number="form.voz_vivo_diarias" type="number" min="1" max="500" class="input" /></div>
        </div>
        <p v-if="config.voz_vivo" class="text-xs text-marca-muted">Este mes se hablaron <b>{{ config.voz_vivo_mes }}</b> minutos en vivo entre todas las empresas.</p>
      </div>
      <div class="flex justify-end gap-2"><button v-if="config.voz_api_key_set" type="button" class="btn-ghost text-xs text-carmin" @click="borrar('voz_api_key')">Borrar clave</button><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
    </form>

    <!-- Fase 121: facturación electrónica de las empresas a través de BigSys (como el CRM) -->
    <div class="card space-y-3 mt-4" data-e2e="arca-bigsys">
      <h2 class="font-bold">Facturación electrónica: certificado de BigSys (ARCA)</h2>
      <p class="text-sm text-marca-muted">Igual que en el CRM: con <b>un solo certificado de BigSys</b>, cada empresa factura sin cargar nada; sólo le delega a BigSys el servicio «Facturación Electrónica» desde su Administrador de Relaciones de Clave Fiscal. Las delegaciones son al <b>CUIT</b> de BigSys, así que las que ya hicieron los clientes del CRM sirven también acá.</p>
      <div class="p-3 rounded-xl text-sm" :class="arca.lista && !arca.vencido ? (arca.produccion ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800') : 'bg-amber-50 text-amber-800'" data-e2e="arca-bigsys-estado">
        <template v-if="arca.lista"><b>Estado:</b> certificado <b>{{ arca.alias }}</b> · CUIT {{ arca.cuit }}{{ arca.razon ? ' · ' + arca.razon : '' }} · vence el {{ arca.vence }}<span v-if="arca.vencido" class="text-carmin font-bold"> (VENCIDO)</span><span v-else-if="arca.vence_pronto" class="font-bold"> (vence pronto)</span> · <b>{{ arca.produccion ? 'PRODUCCIÓN' : 'homologación (prueba)' }}</b> · {{ arca.empresas }} empresa(s) facturando por BigSys.</template>
        <template v-else><b>Estado:</b> sin certificado. Las empresas ven la guía pero no pueden activar la facturación por BigSys.</template>
      </div>
      <p class="text-xs px-3 py-2 rounded-lg bg-violeta-light/50 text-violeta-dark"><b>Importante:</b> conviene que el ERP tenga <b>su propio certificado</b> (mismo CUIT de BigSys, otro alias), y no el mismo archivo del CRM. ARCA entrega un solo ticket de acceso vigente por certificado: si los dos sistemas usan el mismo, se lo pisan y uno se queda sin facturar hasta 12 horas.</p>

      <p v-if="arca.lista" class="text-xs text-marca-muted" data-e2e="arca-recordatorio">Recordatorio: 30 días, 7 días y 1 día antes de que venza (y si llegara a vencer) avisamos a los administradores de BigSys por mail, WhatsApp y celular, con los pasos para renovarlo. Son los mismos canales que las altas nuevas («Altas con aprobación», más arriba).</p>
      <details class="rounded-xl border border-marca-borde p-3" :open="!arca.lista">
        <summary class="cursor-pointer font-semibold text-sm">1 · Generar el pedido de certificado (la clave queda en el servidor)</summary>
        <form @submit.prevent="pedido.post('/admin/sistema/arca/pedido', { preserveScroll: true })" class="grid sm:grid-cols-3 gap-3 mt-3">
          <div><label class="label">CUIT de BigSys</label><input v-model="pedido.cuit" class="input font-mono" placeholder="30-xxxxxxxx-x" /><p v-if="pedido.errors.cuit" class="text-carmin text-xs mt-1">{{ pedido.errors.cuit }}</p></div>
          <div><label class="label">Razón social</label><input v-model="pedido.razon" class="input" /></div>
          <div><label class="label">Alias (sin espacios)</label><input v-model="pedido.alias" class="input font-mono" /><p v-if="pedido.errors.alias" class="text-carmin text-xs mt-1">{{ pedido.errors.alias }}</p></div>
          <div class="sm:col-span-3 flex justify-end"><button class="btn-secondary" :disabled="pedido.processing">{{ arca.pedido ? 'Generar otro pedido' : 'Generar pedido' }}</button></div>
        </form>
        <div v-if="arca.pedido" class="mt-3 text-sm space-y-2" data-e2e="arca-pedido">
          <p>Pedido listo para el alias <b>{{ arca.pedido.alias }}</b>. <a href="/admin/sistema/arca/pedido.csr" class="underline font-semibold">Bajar el pedido (.csr)</a></p>
          <ol class="list-decimal pl-5 text-marca-muted space-y-1 text-xs">
            <li>Entrá a ARCA con la clave fiscal de BigSys → <b>«Administración de Certificados Digitales»</b> (para homologación: «WSASS – Autogestión Certificados Homologación»).</li>
            <li>Agregá el alias <b>{{ arca.pedido.alias }}</b>, subí el pedido (.csr) y bajá el certificado (.crt).</li>
            <li>En <b>«Administrador de Relaciones de Clave Fiscal»</b> → Nueva relación → ARCA → WebServices → <b>«Facturación Electrónica»</b> → Representante: el computador fiscal <b>{{ arca.pedido.alias }}</b>.</li>
            <li>Volvé acá y subí el .crt en el paso 2 (sin clave: ya está en el servidor).</li>
          </ol>
        </div>
      </details>

      <details class="rounded-xl border border-marca-borde p-3" :open="!!arca.pedido">
        <summary class="cursor-pointer font-semibold text-sm">2 · Cargar el certificado</summary>
        <form @submit.prevent="certArca.post('/admin/sistema/arca/certificado', { forceFormData: true, preserveScroll: true, onSuccess: () => certArca.reset() })" class="grid sm:grid-cols-3 gap-3 mt-3">
          <div><label class="label">Certificado (.crt)</label><input type="file" class="input !py-1.5 text-xs" @change="certArca.cert = $event.target.files[0]" /><p v-if="certArca.errors.cert" class="text-carmin text-xs mt-1">{{ certArca.errors.cert }}</p></div>
          <div><label class="label">Clave privada (.key) <span class="font-normal text-marca-muted">· sólo si el pedido NO se generó acá</span></label><input type="file" class="input !py-1.5 text-xs" @change="certArca.key = $event.target.files[0]" /></div>
          <div><label class="label">Ambiente</label><select v-model="certArca.produccion" class="input"><option :value="null">Según el certificado</option><option :value="true">Producción</option><option :value="false">Homologación (prueba)</option></select></div>
          <div class="sm:col-span-3 flex justify-end"><button class="btn-primary" :disabled="certArca.processing || !certArca.cert">Cargar certificado</button></div>
        </form>
      </details>

      <p v-if="prueba?.que === 'arca'" class="whitespace-pre-line text-sm px-3 py-2 rounded-lg" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin-dark'" data-e2e="arca-bigsys-prueba">{{ prueba.texto }}</p>
      <div v-if="arca.lista" class="flex flex-wrap justify-end gap-2">
        <button type="button" class="btn-ghost text-xs text-carmin" @click="borrarArca">Borrar certificado</button>
        <button type="button" class="btn-secondary" @click="router.post('/admin/sistema/arca/ambiente', { produccion: !arca.produccion }, { preserveScroll: true })">Pasar a {{ arca.produccion ? 'homologación' : 'producción' }}</button>
        <Link href="/admin/sistema/arca/probar" method="post" as="button" preserve-scroll class="btn-primary" data-e2e="arca-bigsys-probar">Probar</Link>
      </div>
    </div>

    <!-- Fase 123: copia completa de la base, cifrada y fuera del servidor -->
    <div class="card space-y-3 mt-4" data-e2e="copia-completa">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-bold">Copia completa de la base de datos</h2>
        <span class="badge" :class="copia.clave && copia.disco && copia.ultima && copia.ultima.hace_horas <= 30 && copia.ultima.remoto && !copia.fallo ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">{{ !copia.clave ? 'Falta la clave' : (copia.fallo ? 'La última falló' : (copia.ultima ? `Última: ${copia.ultima.fecha}` : 'Todavía ninguna')) }}</span></div>
      <p class="text-sm text-marca-muted">Para recuperar el ERP <b>entero</b> si se pierde el servidor: todas las empresas, los superadmins, los planes, el certificado de ARCA y las claves de esta pantalla, más la configuración del servidor (con la llave que descifra los datos guardados cifrados). Cada noche a las 03:20 se hace, se cifra con <b>tu clave</b>, se comprueba descifrándola y se sube a <b>{{ copia.disco ?? 'un disco externo' }}</b>, fuera de Hostinger. Afuera quedan las de los últimos 14 días y la primera de cada mes durante un año. Las copias por empresa siguen como siempre.</p>
      <div v-if="!copia.disco" class="p-3 rounded-xl bg-amber-50 text-amber-800 text-sm">No hay disco externo configurado (BACKUP_DISK / OFFSITE_S3_*): la copia quedaría sólo en el servidor.</div>
      <div class="p-3 rounded-xl text-sm" :class="copia.clave ? 'bg-marca-fondo' : 'bg-amber-50 text-amber-800'">
        <template v-if="copia.clave"><b>Clave de las copias:</b> generada el {{ copia.clave_en }}. No se vuelve a mostrar: tiene que estar guardada fuera del sistema (gestor de contraseñas o papel). Sin ella, las copias no se pueden abrir.</template>
        <template v-else><b>Falta la clave de las copias.</b> Generala acá, anotala fuera de la compu y recién ahí las copias empiezan a hacerse.</template>
      </div>
      <div v-if="prueba?.que === 'copia_clave'" class="p-3 rounded-xl border-2 border-carmin/50 bg-white text-sm" data-e2e="copia-clave-nueva">
        <p class="font-bold text-carmin">Anotá esta clave AHORA. No se vuelve a mostrar.</p>
        <p class="font-mono text-lg tracking-wider my-2 select-all">{{ prueba.texto }}</p>
        <p class="text-xs text-marca-muted">Guardala en tu gestor de contraseñas y en un papel en un lugar seguro. No la mandes por chat ni por mail. Sin esta clave no se puede restaurar ninguna copia completa.</p>
      </div>
      <div v-if="prueba?.que === 'copia'" class="p-3 rounded-xl text-sm whitespace-pre-line" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'">{{ prueba.texto }}</div>
      <div v-if="copia.fallo" class="p-3 rounded-xl bg-red-50 text-red-700 text-sm"><b>La última ({{ copia.fallo.fecha }}) falló:</b> {{ copia.fallo.error }}</div>
      <div v-if="copia.lista.length" class="overflow-x-auto"><table class="w-full text-xs">
        <thead class="text-marca-muted text-left"><tr><th class="py-1 pr-3">Fecha</th><th class="pr-3">Tipo</th><th class="pr-3">Estado</th><th class="pr-3">Tamaño</th><th class="pr-3">Tablas</th><th>Fuera del servidor</th></tr></thead>
        <tbody><tr v-for="c in copia.lista" :key="c.id" class="border-t border-marca-borde/60"><td class="py-1 pr-3 tabular-nums">{{ c.fecha }}</td><td class="pr-3">{{ c.origen === 'auto' ? 'automática' : 'a mano' }}</td><td class="pr-3" :class="c.estado === 'ok' ? 'text-emerald-700' : (c.estado === 'error' ? 'text-red-700' : '')" :title="c.error ?? ''">{{ c.estado === 'ok' ? 'comprobada ✓' : (c.estado === 'error' ? 'falló' : 'haciéndose…') }}</td><td class="pr-3 tabular-nums">{{ c.mb !== null ? c.mb + ' MB' : '—' }}</td><td class="pr-3 tabular-nums">{{ c.tablas ?? '—' }}</td><td>{{ c.remoto ? '✓' : '—' }}</td></tr></tbody>
      </table></div>
      <details class="rounded-xl border border-marca-borde p-3 text-sm"><summary class="cursor-pointer font-semibold">Cómo se restaura</summary>
        <ol class="list-decimal pl-5 mt-2 space-y-1 text-marca-muted">
          <li>Bajá de {{ copia.disco ?? 'el disco externo' }} los dos archivos de la fecha que quieras (carpeta <code>completa/AAAA/MM/</code>): <code>…dump.enc</code> y <code>…env.enc</code>.</li>
          <li>En el servidor nuevo, con la clave: <code>openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in ARCHIVO.dump.enc -out erp.dump</code> (y lo mismo con el .env.enc → .env).</li>
          <li>Instalá el ERP con el script de siempre, poné ese .env y restaurá la base: <code>pg_restore --clean --if-exists --no-owner -d BASE erp.dump</code>.</li>
          <li>El paso a paso completo está en <code>docs/MANUAL-DATOS-Y-RESTAURACION.md</code> del repositorio.</li>
        </ol></details>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" class="btn-secondary" @click="claveCopia" data-e2e="copia-generar-clave">{{ copia.clave ? 'Cambiar la clave' : 'Generar la clave' }}</button>
        <Link v-if="copia.clave" href="/admin/sistema/copia/ahora" method="post" as="button" preserve-scroll class="btn-primary" data-e2e="copia-ahora">Hacer una copia ahora</Link>
      </div>
    </div>

    <!-- Fase 120.3: la casilla adonde llegan las facturas de compra por mail -->
    <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3 mt-4" data-e2e="casilla-facturas">
      <h2 class="font-bold">Casilla de facturas (para todas las empresas)</h2>
      <p class="text-sm text-marca-muted">Una casilla de correo común (por ejemplo <b>facturas@bigsysweb.com</b>). Cada empresa recibe su dirección propia, <b>facturas+código@…</b>, y lo que le manden ahí —el PDF o la foto de una factura de compra— aparece leído en <b>Compras</b>, listo para revisar. Se revisa cada 5 minutos. Creá la casilla en tu proveedor de correo y cargá acá los datos de <b>IMAP</b> (en Hostinger: <span class="font-mono">imap.hostinger.com</span>, puerto 993).</p>
      <div class="p-3 rounded-xl text-sm" :class="config.casilla_host && config.casilla_usuario && config.casilla_clave_set ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">
        <b>Estado:</b> {{ config.casilla_host && config.casilla_usuario && config.casilla_clave_set ? 'configurada. Las empresas ya ven su dirección en Compras.' : 'sin configurar. Las empresas igual pueden subir varias facturas juntas desde Compras.' }}
      </div>
      <div class="grid sm:grid-cols-4 gap-3">
        <div class="sm:col-span-2"><label class="label">Servidor IMAP</label><input v-model="form.casilla_host" class="input font-mono" placeholder="imap.hostinger.com" /></div>
        <div><label class="label">Puerto</label><select v-model.number="form.casilla_puerto" class="input"><option :value="993">993 (cifrado)</option><option :value="143">143 (STARTTLS)</option></select></div>
        <div></div>
        <div class="sm:col-span-2"><label class="label">Usuario (la casilla)</label><input v-model="form.casilla_usuario" class="input" placeholder="facturas@bigsysweb.com" autocomplete="off" /></div>
        <div class="sm:col-span-2"><label class="label">Clave {{ config.casilla_clave_set ? '(dejar vacío para no cambiarla)' : '' }}</label><input v-model="form.casilla_clave" type="password" class="input font-mono" autocomplete="new-password" /></div>
        <div class="sm:col-span-2"><label class="label">Dirección pública <span class="font-normal text-marca-muted">(sólo si no es igual al usuario)</span></label><input v-model="form.casilla_direccion" type="email" class="input" placeholder="facturas@bigsysweb.com" /></div>
      </div>
      <p v-if="prueba?.que === 'casilla'" class="text-sm px-3 py-2 rounded-lg" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin-dark'" data-e2e="prueba-casilla">{{ prueba.ok ? '✓' : '✗' }} {{ prueba.texto }}</p>
      <div class="flex flex-wrap justify-end gap-2"><button v-if="config.casilla_clave_set" type="button" class="btn-ghost text-xs text-carmin" @click="borrar('casilla_clave')">Borrar clave</button><button v-if="config.casilla_clave_set || form.casilla_clave" type="button" class="btn-secondary" @click="router.post('/admin/sistema/probar-casilla', { casilla_host: form.casilla_host, casilla_puerto: form.casilla_puerto, casilla_usuario: form.casilla_usuario, casilla_clave: form.casilla_clave }, { preserveScroll: true, preserveState: true })" data-e2e="probar-casilla">Probar la casilla</button><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
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
const props = defineProps({ copia: { type: Object, default: () => ({ clave: false, disco: null, lista: [], ultima: null, fallo: null }) }, arca: { type: Object, default: () => ({ lista: false }) }, aprobacion: { type: Object, default: () => ({}) }, google: { type: Object, default: () => ({}) }, movil: { type: Object, default: () => ({}) }, bigsys: Object, empresasParaBigsys: { type: Array, default: () => [] }, config: Object, ia: Boolean, version: Object, mantenimiento: Object, novedades: { type: Array, default: () => [] }, intereses: { type: Array, default: () => [] } })
const eb = useForm({ nombre: '', business_id: null })
const nov = useForm({ titulo: '', texto: '', url: '', modulo: '', push: false })
const mt = useForm({ activo: !!props.mantenimiento?.activo, mensaje: props.mantenimiento?.mensaje ?? '', hasta: props.mantenimiento?.hasta ?? '' })
const base = location.origin
const host = location.host
const duenos = useForm({ ids: [...(props.aprobacion.duenos ?? [])] }) // Fase 106
const form = useForm({ ...props.config, horas_provisional: props.aprobacion.horas ?? 72, clave_aprobacion: '', aviso_registros_emails: props.aprobacion.emails ?? '', aviso_registros_whatsapp: props.aprobacion.whatsapp ?? '', clave_actual: '', mp_access_token: '', ia_api_key: '', mail_password: '', ml_app_secret: '', tn_app_secret: '', mp_app_secret: '', meta_app_secret: '', voz_api_key: '', casilla_clave: '', casilla_puerto: props.config.casilla_puerto ?? 993, voz_proveedor: props.config.voz_proveedor ?? 'openai', voz_nombre: props.config.voz_nombre ?? null, meta_app_id: props.config.meta_app_id ?? '', meta_config_id: props.config.meta_config_id ?? '', mail_encryption: props.config.mail_encryption || 'tls', fcm_service_account: '', google_client_id: props.google?.client_id ?? '', google_client_secret: '', app_android_url: props.movil?.app_android_url ?? '', app_ios_url: props.movil?.app_ios_url ?? '', android_sha256: props.movil?.android_sha256 ?? '', apple_team_id: props.movil?.apple_team_id ?? '' })
const pruebaA = ref('')
// Fase 121: certificado de BigSys para ARCA
const pedido = useForm({ cuit: props.arca?.sugerido?.cuit ?? '', razon: props.arca?.sugerido?.razon ?? '', alias: props.arca?.pedido?.alias ?? props.arca?.sugerido?.alias ?? 'bigsyserp' })
function borrarArca() { if (confirm('¿Borrar el certificado de BigSys? Las empresas que facturan por BigSys van a emitir simulado.')) router.post('/admin/sistema/arca/borrar', {}, { preserveScroll: true }) }
const certArca = useForm({ cert: null, key: null, produccion: null })
const prueba = computed(() => usePage().props.flash?.prueba) // resultado de "Probar", dentro de su tarjeta
function claveCopia() { if (props.copia.clave && !confirm('¿Generar una clave nueva? Las copias nuevas usan la nueva; las anteriores se siguen abriendo SOLO con la clave vieja (no la tires).')) return; router.post('/admin/sistema/copia/clave', {}, { preserveScroll: true }) }
function borrar(clave) { router.post('/admin/sistema/clave/borrar', { clave }, { preserveScroll: true }) }
</script>
