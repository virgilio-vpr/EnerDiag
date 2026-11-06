import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath } from 'node:url'
import { configDefaults, defineConfig } from 'vitest/config'

const isWindowsMountedDirectory = process.platform === 'linux' && /^\/mnt\/[a-z]\//i.test(process.cwd())
const apiProxy = { '/api': { target: process.env.ENERDIAG_E2E_API_ORIGIN || 'http://127.0.0.1:8000', changeOrigin: true } }

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    fs: { allow: [fileURLToPath(new URL('.', import.meta.url)), fileURLToPath(new URL('../backend/resources/report-texts.json', import.meta.url)), fileURLToPath(new URL('../backend/resources/company-contact.json', import.meta.url))] },
    allowedHosts: process.env.CODESPACE_NAME && process.env.GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN
      ? [5173, 5174].map(port => `${process.env.CODESPACE_NAME}-${port}.${process.env.GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}`)
      : undefined,
    watch: isWindowsMountedDirectory ? { usePolling: true, interval: 500 } : undefined,
    proxy: apiProxy,
  },
  preview: { proxy: apiProxy },
  test: {
    exclude: [...configDefaults.exclude, 'e2e/**'],
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
})
