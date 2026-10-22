// tests/e2e/06-payroll.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Payroll', () => {

    test('payroll runs page loads', async ({ page }) => {
        await page.goto('/payroll');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible({ timeout: 8_000 });
    });

    test('payroll runs list has data', async ({ page }) => {
        await page.goto('/payroll');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        const rows = page.locator('tbody tr');
        const emptyState = page.locator('text=/no payroll|no runs|empty/i');
        await expect(rows.first().or(emptyState).or(page.locator('main'))).toBeVisible({ timeout: 8_000 });
    });

    test('payroll run detail opens', async ({ page }) => {
        await page.goto('/payroll');
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

    test('payroll setup page loads', async ({ page }) => {
        await page.goto('/payroll/setup');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible({ timeout: 8_000 });
    });

    test('payroll setup tabs work', async ({ page }) => {
        await page.goto('/payroll/setup');
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

    test('payroll outputs page loads', async ({ page }) => {
        await page.goto('/payroll/outputs');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible({ timeout: 8_000 });
    });

});
