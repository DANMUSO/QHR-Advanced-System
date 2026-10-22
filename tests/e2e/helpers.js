// tests/e2e/helpers.js
import { expect } from '@playwright/test';

/**
 * Assert no error toast or HTTP error page is visible.
 * Checks for toast-style error messages and full-page error screens
 * without false-positives on legitimate page content containing numbers.
 */
export async function expectNoError(page) {
    // Full-page HTTP error screens (title/heading only, not body text)
    await expect(page.locator('h1:has-text("403"), h1:has-text("404"), h1:has-text("500"), h1:has-text("Server Error"), h1:has-text("Forbidden")')).toHaveCount(0);
    // Toast error notifications (the app uses ui.toast(msg, 'error') which adds a red/error class)
    await expect(page.locator('[class*="toast"][class*="error"], [class*="alert-error"], [role="alert"][class*="error"]')).toHaveCount(0);
}

/**
 * Login with given credentials — use only in tests that create their own fresh browser context.
 */
export async function loginAs(context, email, password = 'Password@123') {
    const page = await context.newPage();
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(500);
    await page.fill('input[type="email"], input[name="email"]', email);
    await page.fill('input[type="password"], input[name="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL(url => !url.pathname.includes('login'), { timeout: 15_000 });
    return page;
}

/**
 * Wait for all pending API requests to settle (no active XHR/fetch).
 * More reliable than a fixed timeout.
 */
export async function waitForApi(page, ms = 2000) {
    await page.waitForLoadState('networkidle').catch(() => page.waitForTimeout(ms));
}
