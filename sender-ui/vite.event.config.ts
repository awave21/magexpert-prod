import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
export default defineConfig({
  base: '/event-editor/',
  plugins: [react()],
  build: { outDir: '../public/event-editor', emptyOutDir: true, rollupOptions: { input: 'event-editor.html' } },
  server: { host: '127.0.0.1', port: 5179 },
})
