// Lance Vitest avec une lettre de lecteur en majuscule sous Windows.
// Un répertoire courant « c:\… » (terminal VS Code) fait charger Vitest deux fois
// et provoque « Vitest failed to find the current suite ».
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const cwd = process.platform === 'win32' ? process.cwd().replace(/^[a-z]:/, (d) => d.toUpperCase()) : process.cwd()
const bin = fileURLToPath(new URL('../node_modules/vitest/vitest.mjs', import.meta.url)).replace(/^[a-z]:/, (d) => d.toUpperCase())
// Le poste définit NODE_ENV=production globalement : les tests exigent le build React de développement.
const env = { ...process.env, NODE_ENV: process.env.NODE_ENV === 'production' ? 'test' : (process.env.NODE_ENV ?? 'test') }
const result = spawnSync(process.execPath, [bin, ...process.argv.slice(2)], { cwd, env, stdio: 'inherit' })
process.exit(result.status ?? 1)
