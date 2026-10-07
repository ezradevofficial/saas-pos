import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Local ports are fixed: web 3008, API 8008 (see CLAUDE.md, Local ports).
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: [
      { find: '@', replacement: fileURLToPath(new URL('./src', import.meta.url)) },
      // shadcn's CLI writes `import { cn } from "cn"`; point it at our cn so the
      // generated files stay stock and still merge token class names correctly.
      { find: /^cn$/, replacement: fileURLToPath(new URL('./src/lib/utils.js', import.meta.url)) },
    ],
  },
  // @app/tokens is CommonJS and linked from the workspace, so pre-bundle it for the browser.
  optimizeDeps: {
    include: ['@app/tokens'],
  },
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
