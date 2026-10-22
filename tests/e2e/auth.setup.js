// tests/e2e/auth.setup.js
import { test as setup, expect } from '@playwright/test';
import path from 'path';

const AUTH_FILE = path.join(import.meta.dirname, '.auth/admin.json');

setup('authenticate as admin', async ({ page }) => {
    // Clear any existing session
    await page.context().clearCookies();

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);

    // If already on dashboard (cached session), sign out first
    const signOut = page.locator('button:has-text("Sign out")');
    if (await signOut.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await signOut.click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(500);
    }

    await expect(page.locator('input[type="email"], input[name="email"]')).toBeVisible({ timeout: 10_000 });

    await page.fill('input[type="email"], input[name="email"]', 'admin@hr.test');
    await page.fill('input[type="password"], input[name="password"]', 'Password@123');
    await page.click('button[type="submit"]');

    await page.waitForURL(url => !url.pathname.includes('login'), { timeout: 15_000 });
    await expect(page).not.toHaveURL(/login/);

    await page.context().storageState({ path: AUTH_FILE });
});
