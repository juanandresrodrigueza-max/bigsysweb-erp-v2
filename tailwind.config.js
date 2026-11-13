import defaultTheme from 'tailwindcss/defaultTheme';

// Tokens de la marca BigSys, tomados de la Guía de Marca y de bigsys.com.ar/bigsys-web (fondo ciruela con brillos magenta,
// píldoras en degradé rojo → magenta → violeta, tarjetas de 24 px, Jost liviana para los títulos).
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                // Texto: Plus Jakarta Sans (se lee mejor en tablas). Títulos: Jost, como en la web. Montserrat queda para el logo.
                sans: ['"Plus Jakarta Sans Variable"', '"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Jost Variable"', 'Jost', '"Plus Jakarta Sans Variable"', ...defaultTheme.fontFamily.sans],
                marca: ['Montserrat', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                carmin:   { DEFAULT: '#e4003f', dark: '#b80033', 70: '#ec4d79', 30: '#f7b3c5', light: '#fde8ee' },
                rosa:     { DEFAULT: '#c8166a', light: '#fbe7f0' },
                lavanda:  { DEFAULT: '#c5bcdd', dark: '#9d92c2', light: '#eeebf5' },
                magenta:  { DEFAULT: '#a42785', light: '#f5e6f0' },
                violeta:  { DEFAULT: '#4f3089', light: '#ece7f5' },
                // La ciruela de la web: el fondo del sitio, de la barra lateral y de las portadas.
                ciruela:  { DEFAULT: '#1e0732', 950: '#12040f', 900: '#1b0a16', 800: '#2b0a4f', 700: '#3b1262', 600: '#4c1a7a' },
                gris:     { DEFAULT: '#d6d1ca', light: '#ebe8e3' },
                marca:    { sidebar: '#1e0732', fondo: '#f6f3f8', texto: '#1b0a16', muted: '#6f6570', borde: '#e6e0ea' },
            },
            backgroundImage: {
                'marca-grad': 'linear-gradient(90deg, #e4003f 0%, #c8166a 55%, #a42785 100%)',
                'violeta-grad': 'linear-gradient(135deg, #4f3089 0%, #a42785 100%)',
                // La portada: ciruela profunda con dos brillos (magenta arriba a la izquierda, violeta abajo a la derecha).
                'ciruela-grad': 'radial-gradient(90% 70% at 10% 0%, rgba(200,22,106,.55) 0%, rgba(200,22,106,0) 60%), radial-gradient(70% 60% at 100% 100%, rgba(79,48,137,.6) 0%, rgba(79,48,137,0) 60%), linear-gradient(180deg, #2b0a4f 0%, #1e0732 55%, #1b0a16 100%)',
                'sidebar-grad': 'radial-gradient(120% 40% at 0% 0%, rgba(200,22,106,.45) 0%, rgba(200,22,106,0) 60%), radial-gradient(80% 30% at 100% 100%, rgba(79,48,137,.55) 0%, rgba(79,48,137,0) 60%), linear-gradient(180deg, #2b0a4f 0%, #1e0732 60%, #1b0a16 100%)',
            },
            boxShadow: {
                card: '0 1px 2px rgba(27,10,22,.04), 0 8px 24px rgba(79,48,137,.06)',
                pop:  '0 12px 40px rgba(27,10,22,.18)',
                glow: '0 10px 30px rgba(228,0,63,.30)',
                'glow-sm': '0 4px 14px rgba(228,0,63,.25)',
            },
            borderRadius: { '2xl': '1.125rem', '3xl': '1.5rem' },
        },
    },
    plugins: [],
};
