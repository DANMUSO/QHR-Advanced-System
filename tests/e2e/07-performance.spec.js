// tests/e2e/07-performance.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Performance', () => {

    test('performance management page loads', async ({ page }) => {
        await page.goto('/performance/manage');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page.locator('main, h1, h2').first()).toBeVisible();
    });

    test('performance management tabs work', async ({ page }) => {
        await page.goto('/performance/manage');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"], .tab-btn');
        const count = await tabs.count();
        for (let i = 0; i < Math.min(count, 6); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(400);
            await expectNoError(page);
        }
    });

    test('learning & development page loads', async ({ page }) => {
        await page.goto('/learning');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

    test('learning tabs work', async ({ page }) => {
        await page.goto('/learning');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"], .tab-btn');
        const count = await tabs.count();
        for (let i = 0; i < Math.min(count, 4); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });

    test('succession & talent page loads', async ({ page }) => {
        await page.goto('/succession');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
    });

    test('succession tabs work', async ({ page }) => {
        await page.goto('/succession');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"], .tab-btn');
        const count = await tabs.count();
        for (let i = 0; i < Math.min(count, 4); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });

});
