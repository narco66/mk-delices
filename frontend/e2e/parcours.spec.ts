import { expect, test, type Page } from '@playwright/test'

const DEMO_PASSWORD = 'Demo-MKD-2026'

async function noHorizontalOverflow(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'Aucun débordement horizontal (NFR-001)').toBeLessThanOrEqual(1)
}

async function login(page: Page, email: string) {
  await page.goto('/connexion')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Mot de passe').fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: 'Se connecter' }).click()
}

test('Accueil : tiramisus mis en avant, aucun débordement à 360 px', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Nos tiramisus' })).toBeVisible()
  await expect(page.getByRole('link', { name: /Tiramisu classique — voir la fiche/ }).first()).toBeVisible()
  await noHorizontalOverflow(page)
})

test('Scénario A + B : commande invitée, paiement confirmé par webhook signé', async ({ page }) => {
  await page.goto('/produits/tiramisu-classique')
  await expect(page.getByRole('heading', { name: 'Tiramisu classique', level: 1 })).toBeVisible()
  // Allergènes visibles avant ajout (CAT-001).
  await expect(page.getByRole('heading', { name: 'Allergènes' })).toBeVisible()
  await expect(page.getByText('Gluten').first()).toBeVisible()
  await noHorizontalOverflow(page)

  await page.getByText('Format 4 personnes').click()
  await page.getByRole('button', { name: 'Augmenter la quantité' }).click()
  await page.getByRole('button', { name: /^Ajouter/ }).click()
  await expect(page.getByText(/ajouté au panier/)).toBeVisible()

  await page.goto('/commander')
  await page.getByLabel('Prénom').fill('Awa')
  await page.getByLabel('Téléphone').fill('77 123 45 67')
  await page.getByLabel('Zone de livraison').selectOption({ index: 1 })
  await page.getByLabel('Quartier').fill('Quartier de test E2E')
  // Le total vient du serveur : 2 × 9 000 + frais de la zone.
  await expect(page.getByText('Selon la zone')).toHaveCount(0)
  await page.locator('fieldset').filter({ hasText: 'Créneau' }).locator('label').first().click()
  await page.getByLabel(/J'accepte les/).check()
  await noHorizontalOverflow(page)
  await page.getByRole('button', { name: /^Confirmer/ }).click()

  // Redirection vers l'écran du prestataire simulé.
  await expect(page.getByText('Prestataire simulé — test')).toBeVisible({ timeout: 20_000 })
  await page.getByRole('button', { name: 'Simuler un paiement réussi' }).click()

  // Retour : statut issu de la vérification serveur, jamais du navigateur.
  await expect(page.getByRole('heading', { name: 'Paiement confirmé' })).toBeVisible({ timeout: 30_000 })
  await page.getByRole('main').getByRole('link', { name: 'Suivre ma commande' }).click()
  await expect(page.getByText('Payée')).toBeVisible()
  await expect(page.getByText('En attente de confirmation').first()).toBeVisible()
})

test('Scénario C : panier envoyé sur WhatsApp = brouillon, sans paiement', async ({ page, context }) => {
  await page.goto('/produits/truffes-chocolat')
  await page.getByRole('button', { name: /^Ajouter/ }).click()
  await expect(page.getByText(/ajouté au panier/)).toBeVisible()
  await page.goto('/commander')
  const popupPromise = context.waitForEvent('page')
  await page.getByRole('button', { name: 'Envoyer mon panier sur WhatsApp' }).click()
  const popup = await popupPromise
  // wa.me redirige vers api.whatsapp.com : on vérifie le numéro et le message encodé.
  await expect.poll(() => popup.url(), { timeout: 15_000 }).toMatch(/(wa\.me\/|phone=)221700000000/)
  expect(decodeURIComponent(popup.url().replace(/\+/g, ' '))).toContain('Référence : MKD-')
  await popup.close()
  await expect(page.getByText(/Brouillon MKD-/)).toBeVisible()
})

test('Scénario G (UI) : un livreur ne peut pas ouvrir le back-office', async ({ page }) => {
  await login(page, 'livreur1@mk-delices.test')
  await expect(page).toHaveURL(/\/livreur/)
  await page.goto('/admin/commandes')
  await expect(page).toHaveURL(/acces-refuse/)
  // Et l'API refuse indépendamment de l'interface.
  const status = await page.evaluate(async () => (await fetch('/api/v1/admin/orders', { headers: { Accept: 'application/json' } })).status)
  expect(status).toBe(403)
})

test('Back-office : le commercial voit les commandes et le tableau de bord', async ({ page }) => {
  await login(page, 'commercial@mk-delices.test')
  await expect(page).toHaveURL(/\/admin/)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await page.goto('/admin/commandes')
  // Tableau sur desktop, cartes sur mobile : on cible l'élément visible.
  await expect(page.getByText(/MKD-/).locator('visible=true').first()).toBeVisible({ timeout: 15_000 })
  await noHorizontalOverflow(page)
})
