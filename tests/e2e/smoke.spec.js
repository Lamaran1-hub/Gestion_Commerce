// @ts-check
const { test, expect } = require('@playwright/test');

test("la page d'accueil répond", async ({ page }) => {
  const response = await page.goto('/');
  expect(response?.status()).toBeLessThan(500);
  await expect(page.locator('body')).toBeVisible();
});
