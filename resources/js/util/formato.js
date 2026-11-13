export const moneda = (n, dec = 2) => '$\u00a0' + Number(n ?? 0).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec })
export const entero = n => Number(n ?? 0).toLocaleString('es-AR')
export const cantidad = n => Number(n ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 3 })
export const tiempo = m => { m = Math.round(Number(m ?? 0)); if (m < 90) return `${m}'`; const h = Math.floor(m / 60), r = m % 60; return r ? `${h} h ${String(r).padStart(2, '0')}` : `${h} h` }
export const hoyISO = () => new Date().toISOString().slice(0, 10)

export const estadoCobro = {
  pendiente: { label: 'Pendiente', clase: 'bg-amber-50 text-amber-700' },
  parcial:   { label: 'Parcial',   clase: 'bg-violeta-light text-violeta' },
  cobrado:   { label: 'Cobrado',   clase: 'bg-emerald-50 text-emerald-700' },
  na:        { label: '',          clase: '' },
}
export const estadoComprobante = {
  borrador: { label: 'Borrador', clase: 'bg-gris-light text-marca-muted' },
  emitido:  { label: 'Emitido',  clase: 'bg-emerald-50 text-emerald-700' },
  anulado:  { label: 'Anulado',  clase: 'bg-carmin-light text-carmin' },
}
