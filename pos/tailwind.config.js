// Token classes only (BR-01): the @app/tokens preset replaces Tailwind's default
// colours, spacing, radii, fonts and type sizes with CSS variables. NativeWind
// sets those variables at runtime through vars() (src/theme/ThemeProvider.jsx);
// the light values are declared on :root because NativeWind only resolves
// variables that exist in the stylesheet.
const { light } = require('@app/tokens/native-themes');

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./App.js', './src/**/*.{js,jsx}'],
  presets: [require('nativewind/preset'), require('@app/tokens/tailwind-v3-preset')],
  theme: {
    extend: {
      // Touch targets: min-h-12 is the 48px spacing token.
      minHeight: ({ theme }) => theme('spacing'),
    },
  },
  plugins: [({ addBase }) => addBase({ ':root': light })],
};
