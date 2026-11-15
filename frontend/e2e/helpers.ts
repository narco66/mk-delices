import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, type Browser, type Page } from '@playwright/test'

export const DEMO_PASSWORD = 'Demo-MKD-2026'
/** Prénom des commandes créées par les tests : marquées is_test en fin de campagne (global-teardown). */
export const E2E_FIRST_NAME = 'E2E'

export const DEMO_ACCOUNTS = [
  'admin@mk-delices.test',
  'commercial@mk-delices.test',
  'preparateur@mk-delices.test',
  'livraison@mk-delices.test',
  'livreur1@mk-delices.test',
] as const

export function authFile(email: string): string {
  return fileURLToPath(new URL(`./.auth/${email.split('@')[0]}.json`, import.meta.url))
}

export async function noHorizontalOverflow(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'Aucun débordement horizontal (NFR-001)').toBeLessThanOrEqual(1)
}

/** Saisie réelle du formulaire de connexion (à réserver aux tests qui vérifient l'authentification elle-même). */
export async function loginWithForm(page: Page, email: string, password = DEMO_PASSWORD) {
  await page.goto('/connexion')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Mot de passe').fill(password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page).not.toHaveURL(/\/connexion/)
}

/** Ouvre la session d'un compte de démonstration (cookies issus de auth.setup.ts) et arrive sur son espace. */
export async function login(page: Page, email: (typeof DEMO_ACCOUNTS)[number]) {
  const state = JSON.parse(readFileSync(authFile(email), 'utf8')) as { cookies: Parameters<ReturnType<Page['context']>['addCookies']>[0] }
  await page.context().clearCookies()
  await page.context().addCookies(state.cookies)
  await page.goto(email.startsWith('livreur') ? '/livreur' : '/admin')
  await expect(page).not.toHaveURL(/\/connexion/)
}

/** Session isolée (cookies distincts) pour un acteur, avec le même gabarit d'écran que le test. */
export async function actor(browser: Browser, page: Page, email: (typeof DEMO_ACCOUNTS)[number]): Promise<Page> {
  const context = await browser.newContext({
    baseURL: new URL(page.url()).origin,
    viewport: page.viewportSize() ?? undefined,
    locale: 'fr-FR',
    timezoneId: 'Africa/Dakar',
    storageState: authFile(email),
  })
  const actorPage = await context.newPage()
  await actorPage.goto(email.startsWith('livreur') ? '/livreur' : '/admin')
  await expect(actorPage).not.toHaveURL(/\/connexion/)
  return actorPage
}

/** Commande invitée complète via l'interface + paiement simulé confirmé par webhook. Renvoie la référence. */
export async function placePaidOrder(page: Page, productSlug = 'tiramisu-classique'): Promise<string> {
  await page.goto(`/produits/${productSlug}`)
  // 2 coupes : le minimum de commande de la zone de démonstration est de 5 000 FCFA.
  await page.getByRole('button', { name: 'Augmenter la quantité' }).click()
  await page.getByRole('button', { name: /^Ajouter/ }).click()
  await expect(page.getByText(/ajouté au panier/)).toBeVisible()
  await page.goto('/commander')
  await page.getByLabel('Prénom').fill(E2E_FIRST_NAME)
  await page.getByLabel('Téléphone').fill('77 123 45 67')
  await page.getByLabel('Zone de livraison').selectOption({ index: 1 })
  await page.getByLabel('Quartier').fill('Quartier de test E2E')
  await page.locator('fieldset').filter({ hasText: 'Créneau' }).locator('label').first().click()
  await page.getByLabel(/J'accepte les/).check()
  await page.getByRole('button', { name: /^Confirmer/ }).click()
  await expect(page.getByText('Prestataire simulé — test')).toBeVisible({ timeout: 20_000 })
  const reference = await page.getByText(/^Commande MKD-/).textContent()
  await page.getByRole('button', { name: 'Simuler un paiement réussi' }).click()
  await expect(page.getByRole('heading', { name: 'Paiement confirmé' })).toBeVisible({ timeout: 30_000 })
  return reference!.replace('Commande ', '').trim()
}
