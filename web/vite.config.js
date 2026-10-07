import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Local ports are fixed: web 3008, API 8008 (see CLAUDE.md, Local ports).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 3008,
    strictPort: true,
  },
  preview: {
    port: 3008,
    strictPort: true,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test/setup.js',
  },
})
