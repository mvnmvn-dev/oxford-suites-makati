/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './public/**/*.html',
    './public/assets/js/**/*.js',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        charcoal: {
          DEFAULT: 'var(--ink)',
          soft: 'var(--ink-soft)',
          muted: 'var(--muted)',
        },
        ember: {
          DEFAULT: '#A8342A',
          deep: '#8B2A22',
          soft: 'var(--ember-soft)',
        },
        brass: {
          DEFAULT: '#C89A4B',
          soft: '#E8D5A8',
          deep: '#8A6A2A',
        },
        parchment: {
          DEFAULT: 'var(--page)',
          deep: 'var(--page-deep)',
          light: 'var(--surface-soft)',
        },
        sage: {
          DEFAULT: '#6B7F5B',
          soft: 'var(--sage-soft)',
          deep: '#3F5234',
        },
        clay: {
          DEFAULT: '#D9773F',
          soft: 'var(--clay-soft)',
          deep: '#9A4E1F',
        },
        line: 'var(--line)',
        surface: 'var(--surface)',
      },
      fontFamily: {
        display: ['Fraunces', 'Georgia', 'serif'],
        sans: ['Public Sans', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        suite: 'var(--shadow-suite)',
        ticket: '0 10px 28px rgba(0,0,0,0.35)',
        lift: 'var(--shadow-lift)',
      },
      animation: {
        'fade-up': 'fadeUp 0.42s cubic-bezier(0.22, 1, 0.36, 1) both',
        'fade-in': 'fadeIn 0.28s ease both',
        'ticket-in': 'ticketIn 0.35s cubic-bezier(0.22, 1, 0.36, 1) both',
        'scale-in': 'scaleIn 0.32s cubic-bezier(0.22, 1, 0.36, 1) both',
      },
      keyframes: {
        fadeUp: {
          '0%': { opacity: '0', transform: 'translateY(10px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        ticketIn: {
          '0%': { opacity: '0', transform: 'translateY(-12px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        scaleIn: {
          '0%': { opacity: '0', transform: 'translateY(12px) scale(0.985)' },
          '100%': { opacity: '1', transform: 'translateY(0) scale(1)' },
        },
      },
    },
  },
  plugins: [],
};
