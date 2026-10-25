import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { configDefaults, defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: process.env.ENERDIAG_E2E_API_ORIGIN ? {
    proxy: { '/api': { target: process.env.ENERDIAG_E2E_API_ORIGIN, changeOrigin: true } },
  } : undefined,
  preview: process.env.ENERDIAG_E2E_API_ORIGIN ? {
    proxy: { '/api': { target: process.env.ENERDIAG_E2E_API_ORIGIN, changeOrigin: true } },
  } : undefined,
  test: {
    exclude: [...configDefaults.exclude, 'e2e/**'],
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
})
