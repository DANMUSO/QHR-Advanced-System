// tests/e2e/09-documents.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Documents', () => {

    test('employee documents page loads', async ({ page }) => {
        await page.goto('/documents/employees');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('document expiry alerts page loads', async ({ page }) => {
        await page.goto('/documents/expiry');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
    });

    test('employee documents list has data or empty state', async ({ page }) => {
        await page.goto('/documents/employees');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('my portal document tab loads', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        const docsTab = page.locator('[role="tab"]:has-text("Documents"), button:has-text("Documents")').first();
        if (await docsTab.isVisible()) {
            await docsTab.click();
            await page.waitForTimeout(600);
            await expectNoError(page);
            // Upload button should be visible
            await expect(page.locator('button:has-text("Upload")').first()).toBeVisible({ timeout: 5_000 });
        }
    });

    test('company documents page loads', async ({ page }) => {
        await page.goto('/documents/company');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

});
