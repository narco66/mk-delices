import { expect, test } from '@playwright/test'
import { DEMO_PASSWORD, noHorizontalOverflow } from './helpers'

/**
 * Espace client (22/11/2026) : connexion par téléphone, favoris, Club MK, « Recommander »,
 * mot de passe oublié et suppression du compte.
 */

const PASSWORD = 'MotDePasse2026'

async function signInWithPhone(page: import('@playwright/test').Page, phone: string, password: string) {
  await page.goto('/connexion')
  await page.getByLabel('Email ou téléphone').fill(phone)
  await page.getByLabel('Mot de passe').fill(password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page).toHaveURL(/\/compte/)
}

test('Client : connexion par téléphone, Club MK et « Recommander »', async ({ page }) => {
  // Compte de démonstration client1 : +221 70 000 00 01, commandes livrées.
  await signInWithPhone(page, '70 000 00 01', DEMO_PASSWORD)
  await expect(page.getByText('Club MK')).toBeVisible()
  await expect(page.getByText(/délices? savourés?/)).toBeVisible()
  await expect(page.getByText(/Encore \d+ commandes? avant/)).toBeVisible()
  await noHorizontalOverflow(page)

  await page.getByRole('button', { name: 'Recommander' }).first().click()
  await expect(page.getByText('Articles ajoutés au panier, aux prix du jour')).toBeVisible()
})

test('Client : favoris, mot de passe oublié puis suppression du compte', async ({ page }) => {
  const id = Date.now().toString().slice(-7)
  const email = `e2e-compte-${id}@example.test`
  const phone = `77${id}`

  await page.goto('/inscription')
  await page.getByLabel('Prénom').fill('E2E')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Téléphone').fill(phone)
  await page.getByLabel(/^Mot de passe/).fill(PASSWORD)
  await page.getByLabel('Confirmation').fill(PASSWORD)
  await page.getByLabel(/J'ai lu la/).check()
  await page.getByRole('button', { name: 'Créer mon compte' }).click()
  await expect(page).toHaveURL(/\/compte/)

  // Favori depuis la fiche produit, retrouvé dans l'espace client.
  await page.goto('/produits/tiramisu-classique')
  await page.getByRole('button', { name: /^Enregistrer Tiramisu classique dans mes favoris/i }).click()
  await expect(page.getByText('Ajouté à vos favoris')).toBeVisible()
  await expect(page.getByRole('button', { name: /^Retirer Tiramisu classique de mes favoris/i })).toHaveAttribute('aria-pressed', 'true')
  await page.goto('/compte')
  await page.getByRole('tab', { name: 'Mes favoris' }).click()
  await expect(page.getByRole('link', { name: /Tiramisu classique — voir la fiche/i })).toBeVisible()

  // Suppression du compte (profil), confirmée par le mot de passe.
  await page.getByRole('tab', { name: 'Profil & préférences' }).click()
  await page.getByRole('button', { name: 'Supprimer mon compte…' }).click()
  await page.getByLabel('Confirmez avec votre mot de passe').fill(PASSWORD)
  await page.getByRole('button', { name: 'Supprimer définitivement' }).click()
  await expect(page.getByText('Votre compte a été supprimé.')).toBeVisible()
  await expect(page).toHaveURL(/\/$/)

  // Plus de connexion possible ; « mot de passe oublié » répond sans révéler si le compte existe.
  await page.goto('/connexion')
  await page.getByLabel('Email ou téléphone').fill(phone)
  await page.getByLabel('Mot de passe').fill(PASSWORD)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page.getByText('Identifiants incorrects.')).toBeVisible()
  await page.getByRole('link', { name: 'Mot de passe oublié ?' }).click()
  await page.getByLabel('Email').fill(email)
  await page.getByRole('button', { name: 'Recevoir le lien' }).click()
  await expect(page.getByText(/Si un compte correspond à cette adresse/)).toBeVisible()
})
