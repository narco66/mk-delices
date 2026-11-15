import { mkdirSync } from 'node:fs'
import { expect, test as setup } from '@playwright/test'
import { authFile, DEMO_ACCOUNTS, DEMO_PASSWORD } from './helpers'

/**
 * Une seule connexion par compte de démonstration, session réutilisée ensuite :
 * la limitation anti-force-brute (5 essais/min/compte) reste active et n'est pas contournée.
 */
setup('sessions des comptes de démonstration', async ({ browser, baseURL }) => {
  mkdirSync(new URL('./.auth/', import.meta.url), { recursive: true })
  for (const email of DEMO_ACCOUNTS) {
    const context = await browser.newContext({ baseURL })
    const page = await context.newPage()
    await page.goto('/connexion')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Mot de passe').fill(DEMO_PASSWORD)
    await page.getByRole('button', { name: 'Se connecter' }).click()
    await expect(page).not.toHaveURL(/\/connexion/)
    await context.storageState({ path: authFile(email) })
    await context.close()
  }
})
