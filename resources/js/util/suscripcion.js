// Etiquetas y colores del ciclo de suscripción, compartidos por el panel superadmin y la pantalla de la empresa.
export const estados = { trial: 'En prueba', active: 'Activa', grace: 'Vencida (gracia)', suspended: 'Suspendida', cancelled: 'Cancelada', suspendida: 'Suspendida', baja: 'Dada de baja', sin_plan: 'Sin plan' }

export const estadoClase = {
  trial: 'bg-lavanda-light text-violeta', active: 'bg-emerald-50 text-emerald-700', grace: 'bg-amber-50 text-amber-700',
  suspended: 'bg-carmin-light text-carmin', suspendida: 'bg-carmin-light text-carmin', cancelled: 'bg-gris-light text-marca-muted', baja: 'bg-gris-light text-marca-muted', sin_plan: 'bg-gris-light text-marca-muted',
}

export const pagoClase = { aprobado: 'bg-emerald-50 text-emerald-700', pendiente: 'bg-amber-50 text-amber-700', rechazado: 'bg-carmin-light text-carmin', anulado: 'bg-gris-light text-marca-muted' }
export const pagoLabel = { aprobado: 'Aprobado', pendiente: 'Pendiente', rechazado: 'Rechazado', anulado: 'Anulado' }
export const cicloLabel = { monthly: 'mensual', yearly: 'anual' }
