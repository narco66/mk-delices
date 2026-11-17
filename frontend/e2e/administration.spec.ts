import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'
import { actor, login, noHorizontalOverflow, placePaidOrder } from './helpers'

/**
 * Écrans d'administration non couverts par les autres parcours : catalogue, promotions, contenus,
 * utilisateurs, paramètres, paiements, remboursements (double contrôle), règlements livreurs, scénario F.
 * Les données créées portent le marqueur « E2E » (suffixe unique) et sont neutralisées en fin de test.
 */
const uid = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`.toUpperCase()
const photo = fileURLToPath(new URL('./fixtures/tiramisu-test.jpg', import.meta.url))

async function apiStatus(page: Page, path: string): Promise<number> {
  return page.evaluate(async (p) => (await fetch(p, { headers: { Accept: 'application/json' } })).status, path)
}

async function dialogSave(page: Page) {
  await page.getByRole('dialog').getByRole('button', { name: 'Enregistrer' }).click()
  await expect(page.getByRole('dialog')).toBeHidden()
}

test('Catalogue : créer, illustrer, publier puis désactiver un produit', async ({ page }) => {
  test.setTimeout(120_000)
  const id = uid()
  const name = `Tiramisu E2E ${id}`
  const slug = `tiramisu-e2e-${id.toLowerCase()}`
  await login(page, 'commercial@mk-delices.test')
  await page.goto('/admin/catalogue')
  await page.getByRole('link', { name: 'Nouveau produit' }).click()

  await page.getByRole('textbox', { name: 'Nom', exact: true }).fill(name)
  await page.getByRole('combobox', { name: 'Catégorie' }).selectOption({ label: 'Tiramisus' })
  await page.getByRole('textbox', { name: 'Adresse (slug)', exact: true }).fill(slug)
  await page.getByRole('textbox', { name: 'Ingrédients', exact: true }).fill('Mascarpone, œufs, café (test E2E)')
  const allergens = page.getByPlaceholder('Ajouter un allergène')
  await allergens.fill('lait')
  await allergens.press('Enter')
  await page.getByRole('textbox', { name: 'SKU', exact: true }).first().fill(`E2E-${id}`)
  await page.getByRole('textbox', { name: 'Libellé', exact: true }).first().fill('Coupe test')
  await page.getByRole('textbox', { name: 'Prix (FCFA)', exact: true }).first().fill('3500')
  await page.getByRole('button', { name: 'Créer le produit' }).click()
  await expect(page).toHaveURL(/\/admin\/catalogue\/[0-9a-f-]{36}$/)
  await noHorizontalOverflow(page)

  // Brouillon : invisible du public.
  const status = await page.request.get(`/api/v1/catalog/products/${slug}`)
  expect(status.status()).toBe(404)

  // Photo : téléversement contrôlé et ré-encodé côté serveur.
  await page.getByRole('button', { name: 'Ajouter', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel(/^Fichier image/).setInputFiles(photo)
  await dialog.getByRole('textbox', { name: 'Texte alternatif', exact: true }).fill(`${name} en coupe`)
  await dialog.getByRole('button', { name: 'Téléverser' }).click()
  await expect(dialog).toBeHidden()
  await expect(page.getByRole('img', { name: `${name} en coupe` })).toBeVisible()

  // Publication → visible et commandable ; désactivation → retiré.
  await page.getByRole('button', { name: 'Publier' }).click()
  await expect(page.getByRole('button', { name: 'Désactiver' })).toBeVisible()
  await page.goto(`/produits/${slug}`)
  await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible()
  await expect(page.getByRole('img', { name: `${name} en coupe` }).first()).toBeVisible()
  await expect(page.getByText('Lait').first()).toBeVisible()
  await page.goBack()
  await page.getByRole('button', { name: 'Désactiver' }).click()
  await expect(page.getByRole('button', { name: 'Publier' })).toBeVisible()
  expect((await page.request.get(`/api/v1/catalog/products/${slug}`)).status()).toBe(404)
})

test('Promotions : créer un code public, le voir côté client puis le désactiver', async ({ page }) => {
  const code = `E2E${uid()}`.slice(0, 20)
  await login(page, 'commercial@mk-delices.test')
  await page.goto('/admin/promotions')
  await page.getByRole('button', { name: 'Nouveau code' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox', { name: 'Code', exact: true }).fill(code)
  await dialog.getByRole('textbox', { name: 'Nom', exact: true }).fill('Promotion de test E2E')
  await dialog.getByRole('combobox', { name: 'Type de remise' }).selectOption('percent')
  await dialog.getByRole('textbox', { name: 'Valeur (%)', exact: true }).fill('10')
  await dialog.getByRole('switch', { name: /^Active/ }).setChecked(true, { force: true })
  await dialog.getByRole('switch', { name: /^Afficher sur la page Promotions/ }).setChecked(true, { force: true })
  await dialogSave(page)
  await expect(page.getByText(code)).toBeVisible()

  await page.goto('/promotions')
  await expect(page.getByText(code)).toBeVisible()

  await page.goto('/admin/promotions')
  await page.getByRole('button', { name: `Modifier ${code}` }).click()
  await page.getByRole('dialog').getByRole('switch', { name: /^Active/ }).setChecked(false, { force: true })
  await dialogSave(page)
  await page.goto('/promotions')
  await expect(page.getByText(code)).toHaveCount(0)
})

test('Contenus : une page en brouillon est invisible, publiée elle est en ligne', async ({ page }) => {
  const slug = `e2e-${uid().toLowerCase()}`
  const title = `Page de test ${slug}`
  await login(page, 'commercial@mk-delices.test')
  await page.goto('/admin/contenus')
  await page.getByRole('button', { name: 'Nouvelle page' }).click()
  await page.getByRole('textbox', { name: 'Titre', exact: true }).fill(title)
  await page.getByRole('textbox', { name: 'Slug', exact: true }).fill(slug)
  await page.getByRole('textbox', { name: 'Contenu', exact: true }).fill('## Section de test\n\nTexte **publié** par le test E2E.')
  await page.getByRole('dialog').getByRole('combobox', { name: 'Statut' }).selectOption('draft')
  await dialogSave(page)
  expect((await page.request.get(`/api/v1/content/pages/${slug}`)).status()).toBe(404)

  const reopen = () => page.getByRole('button', { name: `Modifier ${title}` }).click()
  await reopen()
  await page.getByRole('dialog').getByRole('combobox', { name: 'Statut' }).selectOption('published')
  await dialogSave(page)
  await expect.poll(async () => (await page.request.get(`/api/v1/content/pages/${slug}`)).status()).toBe(200)
  const body = await (await page.request.get(`/api/v1/content/pages/${slug}`)).json()
  expect(body.data.body).toContain('Section de test')

  // Remise en brouillon : la page de test ne reste pas en ligne.
  await reopen()
  await page.getByRole('dialog').getByRole('combobox', { name: 'Statut' }).selectOption('draft')
  await dialogSave(page)
  await expect.poll(async () => (await page.request.get(`/api/v1/content/pages/${slug}`)).status()).toBe(404)
})

test('Utilisateurs : créer un préparateur, il se connecte ; désactivé, il est refusé', async ({ page, browser }) => {
  const id = uid().toLowerCase()
  const email = `preparateur-e2e-${id}@mk-delices.test`
  const password = `E2e-Test-${id}9A`
  await login(page, 'admin@mk-delices.test')
  await page.goto('/admin/utilisateurs')
  await page.getByRole('button', { name: 'Nouvel utilisateur' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox', { name: 'Nom', exact: true }).fill(`Préparateur E2E ${id}`)
  await dialog.getByRole('textbox', { name: 'Email', exact: true }).fill(email)
  await dialog.getByRole('textbox', { name: 'Mot de passe', exact: true }).fill(password)
  await dialog.getByRole('checkbox', { name: /Préparateur/ }).check()
  await dialogSave(page)
  await expect(page.getByText(email)).toBeVisible()

  // Le nouveau compte n'accède qu'à la préparation.
  const ctx = await browser.newContext({ baseURL: new URL(page.url()).origin })
  const preparer = await ctx.newPage()
  await preparer.goto('/connexion')
  await preparer.getByLabel('Email').fill(email)
  await preparer.getByLabel('Mot de passe').fill(password)
  await preparer.getByRole('button', { name: 'Se connecter' }).click()
  await expect(preparer).toHaveURL(/\/admin/)
  // Appels depuis le navigateur (session SPA Sanctum : en-tête Referer requis).
  expect(await apiStatus(preparer, '/api/v1/admin/orders')).toBe(403)
  expect(await apiStatus(preparer, '/api/v1/admin/preparation')).toBe(200)

  // Désactivation : la session ouverte perd immédiatement l'accès.
  await page.getByRole('button', { name: `Modifier Préparateur E2E ${id}` }).click()
  await page.getByRole('dialog').getByRole('switch', { name: /^Compte actif/ }).setChecked(false, { force: true })
  await dialogSave(page)
  expect(await apiStatus(preparer, '/api/v1/admin/preparation')).toBe(403)
  await ctx.close()
})

test('Paramètres et paiements : écrans de consultation', async ({ page }) => {
  await login(page, 'admin@mk-delices.test')
  await page.goto('/admin/parametres')
  await expect(page.getByRole('heading', { name: 'Paramètres' })).toBeVisible()
  await expect(page.getByText('Valeurs à valider').first()).toBeVisible()
  await noHorizontalOverflow(page)
  await page.goto('/admin/paiements')
  await expect(page.getByRole('heading', { name: 'Paiements' })).toBeVisible()
  await expect(page.getByText(/SIM-/).locator('visible=true').first()).toBeVisible()
  await noHorizontalOverflow(page)
})

test('Remboursement : refus d\'une commande payée puis approbation par un autre utilisateur', async ({ page, browser }) => {
  test.setTimeout(150_000)
  const reference = await placePaidOrder(page)

  const commercial = await actor(browser, page, 'commercial@mk-delices.test')
  await commercial.goto(`/admin/commandes?q=${reference}`)
  await commercial.getByRole('link', { name: reference }).locator('visible=true').first().click()
  await commercial.getByRole('button', { name: 'Refuser', exact: true }).click()
  await commercial.getByRole('dialog').getByRole('textbox').fill('Rupture de stock (test E2E)')
  await commercial.getByRole('dialog').getByRole('button', { name: 'Confirmer' }).click()
  await expect(commercial.getByRole('dialog')).toBeHidden()
  await expect(commercial.getByText('Refusée').first()).toBeVisible()

  // Le demandeur ne peut pas approuver sa propre demande.
  await commercial.goto('/admin/remboursements')
  const ownRow = commercial.locator('tr, li, article').filter({ hasText: reference }).locator('visible=true').first()
  await expect(ownRow.getByRole('button', { name: 'Approuver' })).toHaveCount(0)

  // Un administrateur approuve : exécution chez le prestataire (simulé) → remboursé.
  const admin = await actor(browser, page, 'admin@mk-delices.test')
  await admin.goto('/admin/remboursements')
  const row = admin.locator('tr, li, article').filter({ hasText: reference }).locator('visible=true').first()
  await row.getByRole('button', { name: 'Approuver' }).click()
  // Traité, il quitte le filtre « À traiter » : on affiche tous les remboursements.
  await expect(admin.locator('li').filter({ hasText: reference })).toHaveCount(0)
  await admin.getByRole('combobox', { name: 'Statut' }).selectOption('')
  await expect(admin.locator('li').filter({ hasText: reference }).getByText('Effectué')).toBeVisible()

  // Côté client : la page de retour et le suivi affichent le remboursement.
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Commande remboursée' })).toBeVisible()
  await page.getByRole('main').getByRole('link', { name: 'Suivre ma commande' }).click()
  await expect(page.getByText('Remboursée').first()).toBeVisible()
})

test('Scénario F : échec de livraison, décision du responsable, réaffectation, puis règlement', async ({ page, browser }) => {
  test.setTimeout(200_000)
  const reference = await placePaidOrder(page)
  const commercial = await actor(browser, page, 'commercial@mk-delices.test')
  await commercial.goto(`/admin/commandes?q=${reference}`)
  await commercial.getByRole('link', { name: reference }).locator('visible=true').first().click()
  await commercial.getByRole('button', { name: 'Accepter', exact: true }).click()
  await expect(commercial.getByText('Acceptée').first()).toBeVisible()

  const preparer = await actor(browser, page, 'preparateur@mk-delices.test')
  await preparer.goto('/admin/preparation')
  const card = () => preparer.locator('article, li, div').filter({ hasText: reference }).filter({ has: preparer.getByRole('button') }).last()
  await card().getByRole('button', { name: 'Démarrer la préparation' }).click()
  await card().getByRole('button', { name: 'Marquer prête' }).click()
  await preparer.getByRole('dialog').getByRole('checkbox').check()
  await preparer.getByRole('dialog').getByRole('button', { name: 'Confirmer' }).click()

  const manager = await actor(browser, page, 'livraison@mk-delices.test')
  const assign = async () => {
    await manager.goto('/admin/livraisons')
    const select = manager.getByLabel(`Livreur pour ${reference}`)
    await select.selectOption({ label: (await select.locator('option', { hasText: 'Livreur Démo A' }).textContent())!.trim() })
    await select.locator('xpath=ancestor::*[.//button[normalize-space()="Affecter"]][1]').getByRole('button', { name: 'Affecter' }).click()
  }
  await assign()

  const courier = await actor(browser, page, 'livreur1@mk-delices.test')
  const openMission = async () => {
    await courier.goto('/livreur')
    await courier.getByRole('link').filter({ hasText: reference }).first().click()
  }
  await openMission()
  await courier.getByRole('button', { name: 'Accepter' }).click()
  await courier.getByRole('button', { name: "Confirmer l'enlèvement" }).click()
  await courier.getByRole('dialog').getByRole('checkbox').check()
  await courier.getByRole('dialog').getByRole('button', { name: 'Confirmer' }).click()
  await courier.getByRole('button', { name: 'Échec' }).click()
  const fail = courier.getByRole('dialog')
  await fail.getByLabel('Motif').selectOption('client_absent')
  await fail.getByLabel('État du produit').selectOption('intact')
  // Sans contact ni attente déclarés, l'échec n'est pas rémunéré (décision du 17/11/2026) : avertissement affiché.
  await expect(fail.getByText(/ne sera pas rémunérée/)).toBeVisible()
  await fail.getByLabel(/J'ai appelé ou écrit au client et attendu/).check()
  await expect(fail.getByText(/Course rémunérée à 50 %/)).toBeVisible()
  await fail.getByLabel('Précisions').fill('Portail fermé (test E2E)')
  await fail.getByRole('button', { name: "Déclarer l'échec" }).click()
  await expect(fail).toBeHidden()
  await expect(courier.getByRole('button', { name: 'Livrée' })).toHaveCount(0)

  // Le responsable voit l'incident et réaffecte.
  await manager.goto('/admin/livraisons')
  await expect(manager.getByText(reference).first()).toBeVisible()
  await expect(manager.getByText(/Portail fermé \(test E2E\)/).first()).toBeVisible()
  await assign()
  await openMission()
  await courier.getByRole('button', { name: 'Accepter' }).click()
  await courier.getByRole('button', { name: "Confirmer l'enlèvement" }).click()
  await courier.getByRole('dialog').getByRole('checkbox').check()
  await courier.getByRole('dialog').getByRole('button', { name: 'Confirmer' }).click()
  await courier.getByRole('button', { name: 'Livrée' }).click()
  // Client sans son code : remise « sans code », signalée au commercial.
  await courier.getByLabel('Type de preuve').selectOption('recipient_confirmation')
  await expect(courier.getByText(/le commercial vérifiera la livraison/)).toBeVisible()
  await courier.getByLabel('Nom de la personne qui reçoit').fill('Remis au gardien (test E2E)')
  await courier.getByRole('button', { name: 'Confirmer la livraison' }).click()
  await expect(courier.getByText(/Livrée :/)).toBeVisible()

  // Règlement : la mission livrée apparaît dans les montants dus et peut être marquée réglée.
  await manager.goto('/admin/livraisons/reglements')
  // Deux courses dues pour cette commande : l'échec (client absent → 50 %) puis la livraison.
  await expect(manager.getByLabel(`Sélectionner ${reference} (course échouée)`)).toBeVisible()
  await manager.getByLabel(`Sélectionner ${reference} (course échouée)`).check()
  await manager.getByLabel(`Sélectionner ${reference} (livrée)`).check()
  await manager.getByRole('button', { name: /^Marquer 2 réglée/ }).click()
  await expect(manager.getByLabel(new RegExp(`^Sélectionner ${reference}`))).toHaveCount(0)
})
