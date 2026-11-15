import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

/**
 * Les parcours E2E créent de vraies commandes : on les marque is_test (exclues des indicateurs).
 * La commande Laravel refuse de s'exécuter en production.
 */
export default function globalTeardown() {
  const backend = fileURLToPath(new URL('../../backend', import.meta.url))
  try {
    const output = execFileSync('php', ['artisan', 'mk:flag-test-orders', '--first-name=E2E'], { cwd: backend, encoding: 'utf8' })
    console.warn(output.trim())
  } catch (error) {
    console.error('Impossible de marquer les commandes de test :', (error as Error).message)
  }
}
