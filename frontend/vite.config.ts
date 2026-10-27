import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { configDefaults, defineConfig } from 'vitest/config'

const isWindowsMountedDirectory = process.platform === 'linux' && /^\/mnt\/[a-z]\//i.test(process.cwd())

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    allowedHosts: process.env.CODESPACE_NAME && process.env.GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN
      ? [5173, 5174].map(port => `${process.env.CODESPACE_NAME}-${port}.${process.env.GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}`)
      : undefined,
    watch: isWindowsMountedDirectory ? { usePolling: true, interval: 500 } : undefined,
    proxy: process.env.ENERDIAG_E2E_API_ORIGIN
      ? { '/api': { target: process.env.ENERDIAG_E2E_API_ORIGIN, changeOrigin: true } }
      : undefined,
  },
  preview: process.env.ENERDIAG_E2E_API_ORIGIN ? {
    proxy: { '/api': { target: process.env.ENERDIAG_E2E_API_ORIGIN, changeOrigin: true } },
  } : undefined,
  test: {
    exclude: [...configDefaults.exclude, 'e2e/**'],
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
})
