import { execFileSync } from 'node:child_process'
import { existsSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/**
 * PHP à utiliser : PHP_BINARY si fourni, sinon le plus récent PHP 8.4+ de Laragon (poste Windows), sinon « php ».
 * Le projet exige PHP >= 8.4 ; le PATH d'un poste Laragon pointe parfois vers une version plus ancienne.
 */
function phpBinary(): string {
  if (process.env.PHP_BINARY) return process.env.PHP_BINARY
  const laragon = 'C:/laragon/bin/php'
  if (existsSync(laragon)) {
    const candidates = readdirSync(laragon)
      .filter((dir) => /^php-8\.(?:[4-9]|\d{2})\./.test(dir) && existsSync(`${laragon}/${dir}/php.exe`))
      .sort((a, b) => b.localeCompare(a, undefined, { numeric: true }))
      .map((dir) => `${laragon}/${dir}/php.exe`)
    // Une installation sans php.ini n'a pas l'extension PostgreSQL : on l'écarte.
    const usable = candidates.find((php) => {
      try {
        return /^pdo_pgsql$/m.test(execFileSync(php, ['-m'], { encoding: 'utf8' }))
      } catch {
        return false
      }
    })
    if (usable) return usable
  }
  return 'php'
}

/**
 * Les parcours E2E créent de vraies commandes : on les marque is_test (exclues des indicateurs).
 * La commande Laravel refuse de s'exécuter en production.
 */
export default function globalTeardown() {
  const backend = fileURLToPath(new URL('../../backend', import.meta.url))
  try {
    const output = execFileSync(phpBinary(), ['artisan', 'mk:flag-test-orders', '--first-name=E2E'], { cwd: backend, encoding: 'utf8' })
    console.warn(output.trim())
  } catch (error) {
    console.error('Impossible de marquer les commandes de test :', (error as Error).message)
  }
}
