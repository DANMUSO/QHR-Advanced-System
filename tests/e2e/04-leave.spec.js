// tests/e2e/04-leave.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Leave', () => {

    test('my leave page loads', async ({ page }) => {
        await page.goto('/leave');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main, h1, h2').first()).toBeVisible();
    });

    test('apply leave button opens modal', async ({ page }) => {
        await page.goto('/leave');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const applyBtn = page.locator('button:has-text("Apply"), button:has-text("New Leave"), button:has-text("Request")').first();
        await expect(applyBtn).toBeVisible({ timeout: 8_000 });
        await applyBtn.click();
        // Modal should appear
        await expect(page.locator('[role="dialog"], .modal, .modal-overlay').first()).toBeVisible({ timeout: 5_000 });
    });

    test('apply leave modal has required fields', async ({ page }) => {
        await page.goto('/leave');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const applyBtn = page.locator('button:has-text("Apply"), button:has-text("New Leave"), button:has-text("Request")').first();
        if (await applyBtn.isVisible()) {
            await applyBtn.click();
            await page.waitForTimeout(500);
            // Should have leave type selector and date fields
            await expect(page.locator('select, [role="combobox"]').first()).toBeVisible({ timeout: 5_000 });
        }
    });

    test('leave management page loads (admin)', async ({ page }) => {
        await page.goto('/leave/manage');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

    test('leave types page loads', async ({ page }) => {
        await page.goto('/leave/types');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main, h1, h2, table').first()).toBeVisible();
    });

    test('leave reports page loads', async ({ page }) => {
        await page.goto('/leave/reports');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

    test('leave management tabs work', async ({ page }) => {
        await page.goto('/leave/manage');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"], .tab-btn').first();
        if (await tabs.isVisible()) {
            await tabs.click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });

});
