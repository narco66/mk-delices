import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { E2E_FIRST_NAME, login } from './helpers'

/** NFR-006 : objectif WCAG 2.2 AA — aucune violation « serious » ou « critical » détectée automatiquement. */
async function audit(page: Page, label: string) {
  await page.waitForLoadState('networkidle')
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()
  const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
  const report = blocking.map((v) => `${v.id} (${v.impact}) : ${v.help} → ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`).join('\n')
  expect(blocking, `${label}\n${report}`).toEqual([])
}

const PUBLIC_PAGES: [string, string][] = [
  ['Accueil', '/'],
  ['Nos tiramisus', '/nos-tiramisus'],
  ['Boutique', '/boutique'],
  ['Fiche produit', '/produits/tiramisu-classique'],
  ['Livraison', '/livraison'],
  ['FAQ', '/faq'],
  ['Connexion', '/connexion'],
]

for (const [label, url] of PUBLIC_PAGES) {
  test(`Accessibilité — ${label}`, async ({ page }) => {
    await page.goto(url)
    await audit(page, label)
  })
}

test('Accessibilité — formulaire de commande', async ({ page }) => {
  await page.goto('/produits/tiramisu-classique')
  await page.getByRole('button', { name: /^Ajouter/ }).click()
  await expect(page.getByText(/ajouté au panier/)).toBeVisible()
  await page.goto('/commander')
  await expect(page.getByRole('heading', { name: 'Finaliser la commande' })).toBeVisible()
  await audit(page, 'Commande')
})

test('Accessibilité — back-office (tableau de bord et commandes)', async ({ page }) => {
  await login(page, 'admin@mk-delices.test')
  await audit(page, 'Tableau de bord')
  await page.goto('/admin/commandes')
  await audit(page, 'Liste des commandes')
})

/** T-022 : un utilisateur au clavier seul peut compléter le checkout (focus, libellés, erreurs). */
test('Clavier seul : compléter la commande', async ({ page }) => {
  await page.goto('/produits/tiramisu-classique')
  await page.getByText('Format 4 personnes').click()
  await page.getByRole('button', { name: /^Ajouter/ }).click()
  await expect(page.getByText(/ajouté au panier/)).toBeVisible()
  await page.goto('/commander')
  await expect(page.getByRole('heading', { name: 'Finaliser la commande' })).toBeVisible()

  const focusOn = async (name: RegExp | string, role: 'textbox' | 'combobox' | 'radio' | 'checkbox' | 'button' = 'textbox') => {
    const target = page.getByRole(role, { name }).first()
    for (let i = 0; i < 80; i++) {
      if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) return
      await page.keyboard.press('Tab')
    }
    throw new Error(`Champ « ${name} » inatteignable au clavier`)
  }

  // Soumission vide : les erreurs sont annoncées et rattachées aux champs.
  await focusOn(/^Confirmer/, 'button')
  await page.keyboard.press('Enter')
  await expect(page.getByRole('alert').filter({ hasText: 'Indiquez le prénom' })).toBeVisible()
  await expect(page.getByLabel('Prénom')).toHaveAttribute('aria-invalid', 'true')

  await page.getByLabel('Prénom').focus()
  await page.keyboard.type(E2E_FIRST_NAME)
  await focusOn('Téléphone')
  await page.keyboard.type('77 123 45 67')
  await focusOn('Zone de livraison', 'combobox')
  await page.keyboard.press('ArrowDown')
  await focusOn('Quartier')
  await page.keyboard.type('Quartier de test E2E')
  await focusOn(/h00–/, 'radio')
  await page.keyboard.press('Space')
  await focusOn(/J'accepte les/, 'checkbox')
  await page.keyboard.press('Space')
  await focusOn(/^Confirmer/, 'button')
  await page.keyboard.press('Enter')
  await expect(page.getByText('Prestataire simulé — test')).toBeVisible({ timeout: 20_000 })
})
