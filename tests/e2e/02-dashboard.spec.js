// tests/e2e/02-dashboard.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Dashboard', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        // Wait for at least one card/widget to appear rather than a fixed timeout
        await page.locator('main .card, main h1, main h2').first().waitFor({ timeout: 10_000 }).catch(() => {});
    });

    test('dashboard loads without errors', async ({ page }) => {
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('company switcher visible for super-admin', async ({ page }) => {
        await expect(page.locator('select, [role="combobox"]').first()).toBeVisible({ timeout: 8_000 });
    });

    test('at least one dashboard widget renders', async ({ page }) => {
        await expect(page.locator('main .card, main h1, main h2').first()).toBeVisible({ timeout: 10_000 });
    });

    test('navigation sidebar is visible', async ({ page }) => {
        await expect(page.locator('[role="complementary"], aside, nav').first()).toBeVisible({ timeout: 8_000 });
    });

    test('notification bell is visible', async ({ page }) => {
        await expect(page.locator('button[aria-label="Notifications"]')).toBeVisible({ timeout: 8_000 });
    });

    test('global search opens with Ctrl+K', async ({ page }) => {
        await page.keyboard.press('Control+k');
        await expect(page.locator('[role="dialog"], [class*="command"], [class*="palette"]').first()).toBeVisible({ timeout: 5_000 });
    });

});
