// Jurisdicciones de IIBB con su código de Convenio Multilateral (mismo listado que App\Support\JurisdiccionesIibb).
export const JURISDICCIONES = [
  ['AGIP', '901', 'CABA'], ['ARBA', '902', 'Buenos Aires'], ['CAT', '903', 'Catamarca'], ['CBA', '904', 'Córdoba'], ['CTES', '905', 'Corrientes'], ['CHACO', '906', 'Chaco'],
  ['CHUBUT', '907', 'Chubut'], ['ER', '908', 'Entre Ríos'], ['FSA', '909', 'Formosa'], ['JUJUY', '910', 'Jujuy'], ['LPAMPA', '911', 'La Pampa'], ['LRIOJA', '912', 'La Rioja'],
  ['MZA', '913', 'Mendoza'], ['MNES', '914', 'Misiones'], ['NQN', '915', 'Neuquén'], ['RNEGRO', '916', 'Río Negro'], ['SALTA', '917', 'Salta'], ['SJUAN', '918', 'San Juan'],
  ['SLUIS', '919', 'San Luis'], ['SCRUZ', '920', 'Santa Cruz'], ['SFE', '921', 'Santa Fe'], ['SDE', '922', 'Santiago del Estero'], ['TDF', '923', 'Tierra del Fuego'], ['TUC', '924', 'Tucumán'],
].map(([sigla, codigo, nombre]) => ({ sigla, codigo, nombre }))
