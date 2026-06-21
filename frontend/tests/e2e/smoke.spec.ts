import { expect, test } from '@playwright/test'

test('login screen renders from production build', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: 'Masuk', exact: true })).toBeVisible()
  await expect(page.getByLabel('ID pengguna')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Masuk' })).toBeVisible()
})
