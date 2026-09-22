import defaultTheme from 'tailwindcss/defaultTheme';

// Tokens tomados de la Guía de Marca BigSys (colores para pantalla, pág. 9).
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
                sans: ['Montserrat', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                carmin:   { DEFAULT: '#e4003f', dark: '#b80033', 70: '#ec4d79', 30: '#f7b3c5', light: '#fde8ee' },
                lavanda:  { DEFAULT: '#c5bcdd', dark: '#9d92c2', light: '#eeebf5' },
                magenta:  { DEFAULT: '#a42785', light: '#f5e6f0' },
                violeta:  { DEFAULT: '#4f3089', light: '#ece7f5' },
                gris:     { DEFAULT: '#d6d1ca', light: '#ebe8e3' },
                marca:    { sidebar: '#1c1a18', fondo: '#faf9f7', texto: '#1c1a18', muted: '#6f6a62', borde: '#e6e2dc' },
            },
            backgroundImage: {
                'marca-grad': 'linear-gradient(135deg, #e4003f 0%, #a42785 100%)',
                'violeta-grad': 'linear-gradient(135deg, #4f3089 0%, #a42785 100%)',
            },
            boxShadow: {
                card: '0 1px 2px rgba(28,26,24,.04), 0 6px 20px rgba(28,26,24,.05)',
                pop:  '0 10px 40px rgba(28,26,24,.16)',
            },
            borderRadius: { '2xl': '1.125rem' },
        },
    },
    plugins: [],
};
