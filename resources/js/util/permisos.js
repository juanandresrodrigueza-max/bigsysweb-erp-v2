import { usePage } from '@inertiajs/vue3'

// puede('comprobantes', 'anular') según los permisos compartidos por el backend.
export function usePermisos() {
  const page = usePage()
  const puede = (modulo, accion = 'ver') => {
    const p = page.props.auth?.permisos ?? {}
    if (p['*']?.includes?.(accion) || p['*'] === '*') return true
    return !!(p[modulo]?.includes?.(accion))
  }
  return { puede }
}
