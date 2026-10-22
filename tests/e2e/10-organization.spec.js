// tests/e2e/10-organization.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Organization', () => {

    test('org structure page loads', async ({ page }) => {
        await page.goto('/organization');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main, h1, h2').first()).toBeVisible();
    });

    test('org structure tabs work', async ({ page }) => {
        await page.goto('/organization');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"], .tab-btn');
        const count = await tabs.count();
        for (let i = 0; i < Math.min(count, 5); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });

    test('companies page loads', async ({ page }) => {
        await page.goto('/organization/companies');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

    test('users & roles page loads', async ({ page }) => {
        await page.goto('/organization/users');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main, h1, h2').first()).toBeVisible();
    });

    test('users list has data', async ({ page }) => {
        await page.goto('/organization/users');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const rows = page.locator('tbody tr');
        const emptyState = page.locator('text=/no users|empty/i');
        await expect(rows.first().or(emptyState)).toBeVisible({ timeout: 8_000 });
    });

    test('user detail page opens', async ({ page }) => {
        await page.goto('/organization/users');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const firstRow = page.locator('tbody tr').first();
        if (await firstRow.isVisible()) {
            await firstRow.click();
            await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
            await expectNoError(page);
        }
    });

    test('system settings page loads', async ({ page }) => {
        await page.goto('/organization/settings');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

    test('settings tabs work', async ({ page }) => {
        await page.goto('/organization/settings');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"], .tab-btn');
        const count = await tabs.count();
        for (let i = 0; i < Math.min(count, 6); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });

    test('support tickets page loads', async ({ page }) => {
        await page.goto('/organization/support');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

});
