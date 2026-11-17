import { expect, test } from '@playwright/test'
import { actor, noHorizontalOverflow, placePaidOrder } from './helpers'

/**
 * Scénarios D + E de bout en bout, chaque acteur dans sa propre session :
 * client → commercial (acceptation) → préparateur (kanban) → responsable (affectation) → livreur (mobile) → suivi client.
 */
test('Scénarios D + E : de la commande payée à la remise avec preuve', async ({ page, browser }) => {
  test.setTimeout(180_000)
  const reference = await placePaidOrder(page)
  await page.getByRole('main').getByRole('link', { name: 'Suivre ma commande' }).click()
  const trackingUrl = page.url()

  // 1. Commercial : recherche puis acceptation.
  const commercial = await actor(browser, page, 'commercial@mk-delices.test')
  await commercial.goto(`/admin/commandes?q=${reference}`)
  await commercial.getByRole('link', { name: reference }).locator('visible=true').first().click()
  await expect(commercial.getByRole('heading', { name: reference })).toBeVisible()
  await commercial.getByRole('button', { name: 'Accepter', exact: true }).click()
  await expect(commercial.getByText('Acceptée').first()).toBeVisible()
  await noHorizontalOverflow(commercial)

  // 2. Préparateur : kanban, démarrage puis « prête » avec contrôle du conditionnement.
  const preparer = await actor(browser, page, 'preparateur@mk-delices.test')
  await preparer.goto('/admin/preparation')
  const card = () => preparer.locator('article, li, div').filter({ hasText: reference }).filter({ has: preparer.getByRole('button') }).last()
  await card().getByRole('button', { name: 'Démarrer la préparation' }).click()
  await card().getByRole('button', { name: 'Marquer prête' }).click()
  const dialog = preparer.getByRole('dialog')
  await expect(dialog.getByRole('button', { name: 'Confirmer' })).toBeDisabled()
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('button', { name: 'Confirmer' }).click()
  await expect(dialog).toBeHidden()
  await noHorizontalOverflow(preparer)

  // 3. Responsable des livraisons : affectation à un livreur éligible à la zone.
  const manager = await actor(browser, page, 'livraison@mk-delices.test')
  await manager.goto('/admin/livraisons')
  const select = manager.getByLabel(`Livreur pour ${reference}`)
  await select.selectOption({ label: (await select.locator('option', { hasText: 'Livreur Démo A' }).textContent())!.trim() })
  await select.locator('xpath=ancestor::*[.//button[normalize-space()="Affecter"]][1]').getByRole('button', { name: 'Affecter' }).click()
  await expect(manager.getByText(/affect/i).first()).toBeVisible()

  // 4. Livreur (interface smartphone) : accepte, enlève, remet avec preuve.
  const courier = await actor(browser, page, 'livreur1@mk-delices.test')
  await courier.setViewportSize({ width: 360, height: 780 })
  await courier.goto('/livreur')
  await courier.getByRole('link').filter({ hasText: reference }).first().click()
  // Coordonnées du client masquées avant acceptation, dévoilées ensuite (SECURITY §5).
  await expect(courier.getByRole('link', { name: 'Appeler' })).toHaveCount(0)
  await courier.getByRole('button', { name: 'Accepter' }).click()
  await expect(courier.getByRole('link', { name: 'Appeler' })).toHaveAttribute('href', 'tel:+221771234567')
  await courier.getByRole('button', { name: "Confirmer l'enlèvement" }).click()
  await courier.getByRole('dialog').getByRole('checkbox').check()
  await courier.getByRole('dialog').getByRole('button', { name: 'Confirmer' }).click()
  // Le client lit son code de remise sur son suivi et le donne au livreur.
  await page.goto(trackingUrl)
  const code = (await page.locator('p[aria-label^="Code de remise"]').textContent())!.trim()
  expect(code).toMatch(/^\d{4}$/)
  await courier.getByRole('button', { name: 'Livrée' }).click()
  await courier.getByLabel('Code de remise donné par le client').fill(code === '0000' ? '1111' : '0000')
  await courier.getByRole('button', { name: 'Confirmer la livraison' }).click()
  await expect(courier.getByText(/Code incorrect\. Il vous reste 4 essai/)).toBeVisible()
  await courier.getByLabel('Code de remise donné par le client').fill(code)
  await courier.getByRole('button', { name: 'Confirmer la livraison' }).click()
  await expect(courier.getByText(/Livrée :/)).toBeVisible()
  await noHorizontalOverflow(courier)

  // 5. Le client voit sa commande livrée.
  await page.goto(trackingUrl)
  await expect(page.getByText('Livrée').first()).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Votre avis compte' })).toBeVisible()
})

test('Préparation démarrée par erreur : annulation depuis la fiche commande, avec motif', async ({ page, browser }) => {
  test.setTimeout(120_000)
  const reference = await placePaidOrder(page)

  const commercial = await actor(browser, page, 'commercial@mk-delices.test')
  await commercial.goto(`/admin/commandes?q=${reference}`)
  await commercial.getByRole('link', { name: reference }).locator('visible=true').first().click()
  await commercial.getByRole('button', { name: 'Accepter', exact: true }).click()
  await expect(commercial.getByText('Acceptée').first()).toBeVisible()

  const preparer = await actor(browser, page, 'preparateur@mk-delices.test')
  await preparer.goto('/admin/preparation')
  const card = () => preparer.locator('li').filter({ has: preparer.getByRole('link', { name: reference, exact: true }) })
  await card().getByRole('button', { name: 'Démarrer la préparation' }).click()
  await expect(card().getByRole('button', { name: /Annuler le démarrage/ })).toBeVisible()

  // Depuis la fiche commande (régression corrigée : le bouton n'ouvrait aucune fenêtre).
  await card().getByRole('link', { name: reference, exact: true }).click()
  await preparer.getByRole('button', { name: 'Annuler le démarrage' }).click()
  const dialog = preparer.getByRole('dialog')
  await expect(dialog.getByRole('heading', { name: 'Annuler le démarrage de la préparation' })).toBeVisible()
  await dialog.getByRole('button', { name: 'Confirmer' }).click()
  await expect(dialog.getByText('Indiquez un motif (3 caractères minimum).')).toBeVisible()
  await dialog.getByRole('textbox').fill('Démarrée par erreur (test E2E)')
  await dialog.getByRole('button', { name: 'Confirmer' }).click()
  await expect(dialog).toBeHidden()
  await expect(preparer.getByText('Commande : Acceptée')).toBeVisible()
  // Le motif est tracé dans l'historique, visible par le commercial (le préparateur n'a pas accès au journal).
  await commercial.reload()
  await expect(commercial.getByText('Démarrée par erreur (test E2E)')).toBeVisible()
})

test('Livreur : accepter puis rendre une mission avant l\'enlèvement (WhatsApp disponible)', async ({ page, browser }) => {
  test.setTimeout(180_000)
  const reference = await placePaidOrder(page)

  const commercial = await actor(browser, page, 'commercial@mk-delices.test')
  await commercial.goto(`/admin/commandes?q=${reference}`)
  await commercial.getByRole('link', { name: reference }).locator('visible=true').first().click()
  await commercial.getByRole('button', { name: 'Accepter', exact: true }).click()
  await expect(commercial.getByText('Acceptée').first()).toBeVisible()

  const preparer = await actor(browser, page, 'preparateur@mk-delices.test')
  await preparer.goto('/admin/preparation')
  const card = () => preparer.locator('li').filter({ has: preparer.getByRole('link', { name: reference, exact: true }) })
  await card().getByRole('button', { name: 'Démarrer la préparation' }).click()
  await card().getByRole('button', { name: 'Marquer prête' }).click()
  await preparer.getByRole('dialog').getByRole('checkbox').check()
  await preparer.getByRole('dialog').getByRole('button', { name: 'Confirmer' }).click()

  const manager = await actor(browser, page, 'livraison@mk-delices.test')
  await manager.goto('/admin/livraisons')
  const select = manager.getByLabel(`Livreur pour ${reference}`)
  await select.selectOption({ label: (await select.locator('option', { hasText: 'Livreur Démo A' }).textContent())!.trim() })
  await select.locator('xpath=ancestor::*[.//button[normalize-space()="Affecter"]][1]').getByRole('button', { name: 'Affecter' }).click()

  const courier = await actor(browser, page, 'livreur1@mk-delices.test')
  await courier.setViewportSize({ width: 360, height: 780 })
  await courier.goto('/livreur')
  await courier.getByRole('link').filter({ hasText: reference }).first().click()
  await courier.getByRole('button', { name: 'Accepter' }).click()
  await expect(courier.getByRole('link', { name: 'WhatsApp' })).toHaveAttribute('href', 'https://wa.me/221771234567')

  await courier.getByRole('button', { name: 'Rendre' }).click()
  const dialog = courier.getByRole('dialog')
  await expect(dialog.getByRole('button', { name: 'Rendre la mission' })).toBeDisabled()
  await dialog.getByRole('textbox').fill('Crevaison (test E2E)')
  await dialog.getByRole('button', { name: 'Rendre la mission' }).click()
  await expect(courier.getByText('Mission rendue : le responsable est prévenu')).toBeVisible()
  await expect(courier.getByRole('link', { name: 'WhatsApp' })).toHaveCount(0)
  await noHorizontalOverflow(courier)

  // La commande peut de nouveau être confiée à un livreur.
  await manager.reload()
  await expect(manager.getByLabel(`Livreur pour ${reference}`)).toBeVisible()
})
