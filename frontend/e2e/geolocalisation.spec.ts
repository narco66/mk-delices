import { expect, test, type Page } from '@playwright/test'
import { E2E_FIRST_NAME, noHorizontalOverflow } from './helpers'

/**
 * Géolocalisation du client (docs/audit-geolocalisation/06-RAPPORT-DE-TESTS.md).
 * Position simulée par Playwright (Mermoz, Dakar) ; aucune carte ni géocodage tant que MAPS_TILES_URL et
 * MAPS_GEOCODER_KEY ne sont pas configurés : c'est le mode réel de la préproduction.
 */
const MERMOZ = { latitude: 14.7106, longitude: -17.4747, accuracy: 20 }

async function fillCart(page: Page) {
  await page.goto('/produits/tiramisu-classique')
  await page.getByRole('button', { name: 'Augmenter la quantité' }).click()
  await page.getByRole('button', { name: /^Ajouter/ }).click()
  await expect(page.getByText(/ajouté au panier/)).toBeVisible()
  await page.goto('/commander')
}

test.describe('avec la localisation autorisée', () => {
  test.use({ geolocation: MERMOZ, permissions: ['geolocation'] })

  test('position détectée sur demande, confirmée, puis transmise avec la commande', async ({ page }) => {
    await fillCart(page)
    const picker = page.getByText('Où livrer ?')
    await expect(picker).toBeVisible()
    await noHorizontalOverflow(page)

    await page.getByRole('button', { name: 'Utiliser ma position actuelle' }).click()
    await page.getByRole('button', { name: 'Confirmer cette position' }).click()
    await expect(page.getByText('Position de livraison confirmée')).toBeVisible()

    await page.getByLabel('Prénom').fill(E2E_FIRST_NAME)
    await page.getByLabel('Téléphone').fill('77 123 45 67')
    // Zones pas encore dessinées : la zone reste choisie par le client.
    await page.getByLabel('Zone de livraison').selectOption({ index: 1 })
    await page.getByLabel('Quartier').fill('Mermoz (test E2E géolocalisation)')
    await page.locator('fieldset').filter({ hasText: 'Créneau' }).locator('label').first().click()
    await page.getByLabel(/J'accepte les/).check()

    const placed = page.waitForRequest((r) => r.url().endsWith('/api/v1/checkout/orders') && r.method() === 'POST')
    await page.getByRole('button', { name: /^Confirmer/ }).click()
    const request = await placed
    const body = request.postDataJSON()
    const response = await request.response()
    expect(response?.status(), await response?.text()).toBe(201)
    expect(body.delivery).toMatchObject({ lat: MERMOZ.latitude, lng: MERMOZ.longitude, location_source: 'gps', accuracy_m: 20 })
    expect(typeof body.expected_total_xof).toBe('number')
    await expect(page.getByText('Prestataire simulé — test')).toBeVisible({ timeout: 20_000 })
  })
})

test.describe('avec la localisation refusée', () => {
  test.use({ permissions: [] })

  test('le refus est expliqué et la commande reste possible par saisie manuelle', async ({ page, context }) => {
    // Refus explicite simulé côté navigateur (comportement identique à « Bloquer » dans la fenêtre d'autorisation).
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'geolocation', {
        configurable: true,
        value: { getCurrentPosition: (_ok: unknown, ko: (e: object) => void) => ko({ code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 }) },
      })
    })
    await fillCart(page)
    await page.getByRole('button', { name: 'Utiliser ma position actuelle' }).click()
    await expect(page.getByText(/Localisation refusée/)).toBeVisible()

    await page.getByLabel('Prénom').fill(E2E_FIRST_NAME)
    await page.getByLabel('Téléphone').fill('77 123 45 67')
    await page.getByLabel('Zone de livraison').selectOption({ index: 1 })
    await page.getByLabel('Quartier').fill('Saisie manuelle (test E2E)')
    await page.locator('fieldset').filter({ hasText: 'Créneau' }).locator('label').first().click()
    await page.getByLabel(/J'accepte les/).check()
    const placed = page.waitForRequest((r) => r.url().endsWith('/api/v1/checkout/orders') && r.method() === 'POST')
    await page.getByRole('button', { name: /^Confirmer/ }).click()
    expect((await placed).postDataJSON().delivery.lat).toBeUndefined()
    await expect(page.getByText('Prestataire simulé — test')).toBeVisible({ timeout: 20_000 })
  })
})

test('position hors de la région de Dakar : refusée par le serveur', async ({ request }) => {
  const res = await request.post('/api/v1/geo/resolve', { data: { lat: 48.85, lng: 2.35 }, headers: { Accept: 'application/json' } })
  expect(res.status()).toBe(422)
  expect((await res.json()).error.code).toBe('location_out_of_region')
})
