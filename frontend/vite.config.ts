import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { loadEnv } from 'vite'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const frontendDir = dirname(fileURLToPath(import.meta.url))
const envDir = resolve(frontendDir, '..')

function list(value: string | undefined) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, envDir, '')
  const reverbHost = env.VITE_REVERB_HOST || env.REVERB_HOST || '127.0.0.1'
  const reverbPort = env.VITE_REVERB_PORT || env.REVERB_PORT || '8080'
  const reverbScheme = env.VITE_REVERB_SCHEME || env.REVERB_SCHEME || 'http'
  const reverbWsScheme = reverbScheme === 'https' ? 'wss' : 'ws'

  return {
    envDir,
    plugins: [react(), tailwindcss()],
    server: {
      host: env.VITE_DEV_HOST || '127.0.0.1',
      port: Number(env.VITE_DEV_PORT || 5173),
      strictPort: true,
      allowedHosts: list(env.VITE_ALLOWED_HOSTS),
      proxy: {
        '/api': {
          target: 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/broadcasting': {
          target: 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/sanctum': {
          target: 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/storage': {
          target: 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/app': {
          target: `${reverbWsScheme}://${reverbHost}:${reverbPort}`,
          changeOrigin: true,
          ws: true,
        },
      },
    },
    test: {
      environment: 'jsdom',
      setupFiles: './src/test/setup.ts',
      css: true,
      exclude: ['node_modules/**', 'dist/**', 'tests/e2e/**'],
    },
  }
})
