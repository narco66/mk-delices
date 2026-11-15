import { defineConfig, devices } from '@playwright/test'

/**
 * Parcours E2E sur l'application rÃ©elle (Vite :5173 â†’ API Laravel :8000 â†’ PostgreSQL).
 * PrÃ©requis : backend dÃ©marrÃ© avec le jeu de dÃ©monstration (php artisan db:seed) et simulateur de paiement actif.
 */
export default defineConfig({
  testDir: './e2e',
  globalTeardown: './e2e/global-teardown.ts',
  timeout: 60_000,
  // Serveur PHP de développement mono-processus sous Windows : marge pour les sessions concurrentes.
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    locale: 'fr-FR',
    timezoneId: 'Africa/Dakar',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // E2E_CHANNEL=msedge : utilise le navigateur Edge du poste au lieu des binaires Playwright tÃ©lÃ©chargÃ©s.
    channel: process.env.E2E_CHANNEL || undefined,
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    { name: 'mobile', use: { ...devices['Pixel 7'], viewport: { width: 360, height: 780 }, channel: process.env.E2E_CHANNEL || undefined }, dependencies: ['setup'] },
    { name: 'desktop', use: { ...devices['Desktop Chrome'], channel: process.env.E2E_CHANNEL || undefined }, dependencies: ['setup'] },
  ],
})
