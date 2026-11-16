import type { Config } from 'tailwindcss';

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        cream: 'rgb(var(--cream) / <alpha-value>)',
        ivory: 'rgb(var(--ivory) / <alpha-value>)',
        sand: 'rgb(var(--sand) / <alpha-value>)',
        'gold-light': 'rgb(var(--gold-light) / <alpha-value>)',
        gold: 'rgb(var(--gold) / <alpha-value>)',
        'gold-dark': 'rgb(var(--gold-dark) / <alpha-value>)',
        blush: 'rgb(var(--blush) / <alpha-value>)',
        rose: 'rgb(var(--rose) / <alpha-value>)',
        'rose-dark': 'rgb(var(--rose-dark) / <alpha-value>)',
        ink: 'rgb(var(--ink) / <alpha-value>)',
        'ink-soft': 'rgb(var(--ink-soft) / <alpha-value>)',
        success: 'rgb(var(--success) / <alpha-value>)',
        warning: 'rgb(var(--warning) / <alpha-value>)',
        danger: 'rgb(var(--danger) / <alpha-value>)',
      },
      fontFamily: {
        serif: ['"Cormorant Garamond"', 'Georgia', 'serif'],
        sans: ['Montserrat', 'system-ui', 'sans-serif'],
      },
      backgroundImage: {
        'gold-gradient': 'linear-gradient(135deg, #F2CF62 0%, #D4A12A 50%, #9A6712 100%)',
      },
      boxShadow: {
        soft: '0 4px 20px -8px rgba(43, 33, 21, 0.15)',
      },
      letterSpacing: {
        section: '0.08em',
      },
      keyframes: {
        fadeIn: { from: { opacity: '0' }, to: { opacity: '1' } },
        rise: { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
      },
      animation: {
        'fade-in': 'fadeIn .3s ease-out',
        rise: 'rise .35s ease-out',
      },
    },
  },
  plugins: [],
} satisfies Config;
