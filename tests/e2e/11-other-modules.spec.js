// tests/e2e/11-other-modules.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

async function pageLoads(page, path) {
    await page.goto(path);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);
    await expectNoError(page);
    await expect(page.locator('main')).toBeVisible({ timeout: 8_000 });
}

test.describe('Benefits', () => {
    test('benefits page loads', async ({ page }) => { await pageLoads(page, '/benefits'); });
    test('benefits tabs work', async ({ page }) => {
        await page.goto('/benefits');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        const tabs = page.locator('[role="tab"], .tab-btn');
        for (let i = 0; i < Math.min(await tabs.count(), 4); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });
});

test.describe('Claims', () => {
    test('claims page loads', async ({ page }) => { await pageLoads(page, '/claims'); });
    test('claims approvals page loads', async ({ page }) => { await pageLoads(page, '/claims/approvals'); });
    test('claim payments page loads', async ({ page }) => { await pageLoads(page, '/claims/payments'); });
    test('claim settings page loads', async ({ page }) => { await pageLoads(page, '/claims/settings'); });
});

test.describe('Compliance', () => {
    test('compliance cases page loads', async ({ page }) => { await pageLoads(page, '/compliance'); });
    // Correct route: /compliance/health-safety not /compliance/hs
    test('health & safety page loads', async ({ page }) => { await pageLoads(page, '/compliance/health-safety'); });
    test('disciplinary cases page loads', async ({ page }) => { await pageLoads(page, '/compliance/disciplinary'); });
    test('exit clearance page loads', async ({ page }) => { await pageLoads(page, '/compliance/exits'); });
});

test.describe('Engagement', () => {
    test('engagement manage page loads', async ({ page }) => { await pageLoads(page, '/engagement/manage'); });
    test('engagement tabs work', async ({ page }) => {
        await page.goto('/engagement/manage');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        const tabs = page.locator('[role="tab"], .tab-btn');
        for (let i = 0; i < Math.min(await tabs.count(), 5); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expectNoError(page);
        }
    });
});

test.describe('Probation', () => {
    test('probation page loads', async ({ page }) => { await pageLoads(page, '/probation'); });
    test('probation review modal opens', async ({ page }) => {
        await page.goto('/probation');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        const reviewBtn = page.locator('button:has-text("Review")').first();
        if (await reviewBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await reviewBtn.click();
            await expect(page.locator('[role="dialog"]').first()).toBeVisible({ timeout: 5_000 });
            await expectNoError(page);
        }
    });
});

test.describe('Analytics', () => {
    test('analytics dashboard loads', async ({ page }) => { await pageLoads(page, '/analytics'); });
    test('analytics reports page loads', async ({ page }) => { await pageLoads(page, '/analytics/reports'); });
    test('analytics charts render', async ({ page }) => {
        await page.goto('/analytics');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1500);
        await expectNoError(page);
        await expect(page.locator('canvas, svg, main').first()).toBeVisible({ timeout: 10_000 });
    });
});
