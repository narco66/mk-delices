import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// Le SPA et l'API partagent la même origine en développement (proxy) :
// cookies de session Sanctum first-party, HttpOnly, SameSite=Lax, sans CORS.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backend = env.VITE_BACKEND_URL || 'http://127.0.0.1:8000'

  return {
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    // Pré-bundle stable : évite une ré-optimisation en cours de session (deux copies de React).
    optimizeDeps: {
      include: [
        'react',
        'react-dom/client',
        'react-router',
        '@tanstack/react-query',
        'react-hook-form',
        '@hookform/resolvers/zod',
        'zod',
        'sonner',
        'lucide-react',
        'clsx',
        '@radix-ui/react-dialog',
        'react-markdown',
        'recharts',
      ],
    },
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': { target: backend, changeOrigin: false },
        '/sanctum': { target: backend, changeOrigin: false },
        '/storage': { target: backend, changeOrigin: false },
      },
    },
    build: {
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (id.includes('recharts') || id.includes('d3-')) return 'charts'
            if (/node_modules[/\\](react|react-dom|scheduler|react-router)[/\\]/.test(id)) return 'react'
          },
        },
      },
    },
  }
})
