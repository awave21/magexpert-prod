import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Интерфейс открывается по /sender (в сборке это маршрут Laravel).
// Файлы сборки лежат в public/sender-static: папка не должна совпадать с адресом /sender.
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/sender-static/' : '/sender/',
  plugins: [react()],
  build: { outDir: '../public/sender-static', emptyOutDir: true },
  server: {
    port: 5174,
    proxy: {
      '/api/': { target: process.env.SENDER_API_URL ?? 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
}))
