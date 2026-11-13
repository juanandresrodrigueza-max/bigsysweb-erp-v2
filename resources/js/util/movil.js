// Fase 93: el puente con la app nativa (Capacitor, iPhone y Android). Afuera de la app no hace nada.
// Los plugins se cargan sólo cuando la página corre adentro de la app, así la web no carga nada de más.
import { router } from '@inertiajs/vue3'

export const esApp = () => typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()
export const plataforma = () => (esApp() ? window.Capacitor.getPlatform() : 'web')

let iniciada = false
export async function iniciarMovil() {
  if (!esApp() || iniciada) return
  iniciada = true
  document.documentElement.classList.add('es-app', 'es-app-' + plataforma())
  try {
    const [{ App }, { StatusBar, Style }, { SplashScreen }] = await Promise.all([import('@capacitor/app'), import('@capacitor/status-bar'), import('@capacitor/splash-screen')])
    // Barra de estado ciruela con texto claro; en iPhone la cabecera deja lugar al notch (CSS .es-app).
    try { await StatusBar.setStyle({ style: Style.Dark }); if (plataforma() === 'android') await StatusBar.setBackgroundColor({ color: '#1e0732' }) } catch {}
    // El botón "atrás" de Android: vuelve en el historial; en la raíz minimiza la app en vez de cerrarla.
    App.addListener('backButton', ({ canGoBack }) => { if (canGoBack) window.history.back(); else App.minimizeApp() })
    // Al volver del fondo, refresca los datos de la pantalla (alertas, mesas, caja) sin recargar todo.
    App.addListener('appStateChange', ({ isActive }) => { if (isActive) router.reload({ only: ['alertas', 'novedades'] }) })
    try { await SplashScreen.hide() } catch {}
  } catch (e) { console.warn('movil: sin plugins base', e) }
  await registrarPush()
}

// Pide permiso y registra el celular en el servidor. El token se renueva solo y se vuelve a mandar cada vez que abre.
export async function registrarPush() {
  if (!esApp()) return false
  try {
    const { PushNotifications } = await import('@capacitor/push-notifications')
    let p = await PushNotifications.checkPermissions()
    if (p.receive === 'prompt' || p.receive === 'prompt-with-rationale') p = await PushNotifications.requestPermissions()
    if (p.receive !== 'granted') return false
    PushNotifications.addListener('registration', async ({ value }) => {
      try { await window.axios.post('/movil/dispositivo', { token: value, plataforma: plataforma(), modelo: await modelo(), version_app: await versionApp() }) } catch {}
    })
    PushNotifications.addListener('registrationError', e => console.warn('push: sin registro', e))
    // Llegó con la app abierta: la campanita se refresca; no se interrumpe lo que está haciendo.
    PushNotifications.addListener('pushNotificationReceived', () => { try { router.reload({ only: ['alertas', 'novedades'] }) } catch {} })
    // Tocó el aviso: va a la pantalla del aviso.
    PushNotifications.addListener('pushNotificationActionPerformed', ({ notification }) => { const url = notification?.data?.url; if (url && typeof url === 'string' && url.startsWith('/')) router.visit(url) })
    if (plataforma() === 'android') { try { await PushNotifications.createChannel({ id: 'bigsys', name: 'Avisos de BigSys', description: 'Alertas, cobros, pedidos y el parte del día', importance: 4, visibility: 1, vibration: true }) } catch {} }
    await PushNotifications.register()
    return true
  } catch (e) { console.warn('movil: sin push', e); return false }
}

// Darse de baja de los avisos de este celular (al cerrar sesión, por ejemplo).
export async function bajaPush() {
  if (!esApp()) return
  try { await window.axios.post('/movil/dispositivo/baja', {}) } catch {}
}

async function modelo() { try { const { Device } = await import('@capacitor/device'); const i = await Device.getInfo(); return `${i.manufacturer ?? ''} ${i.model ?? ''} · ${i.operatingSystem ?? ''} ${i.osVersion ?? ''}`.trim() } catch { return navigator.userAgent.slice(0, 120) } }
async function versionApp() { try { const { App } = await import('@capacitor/app'); const i = await App.getInfo(); return `${i.version} (${i.build})` } catch { return null } }
