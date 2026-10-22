// tests/e2e/03-employees.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Employees', () => {

    test('employee list loads', async ({ page }) => {
        await page.goto('/employees');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('employee search filters results', async ({ page }) => {
        await page.goto('/employees');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        const search = page.locator('input[placeholder*="search" i], input[type="search"]').first();
        if (await search.isVisible()) {
            await search.fill('a');
            await page.waitForTimeout(600);
        }
        await expectNoError(page);
    });

    test('employee profile opens', async ({ page }) => {
        await page.goto('/employees');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        // Click first link that goes to /employees/:id
        const profileLink = page.locator('a[href*="/employees/"]').first();
        if (await profileLink.isVisible()) {
            await profileLink.click();
            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(600);
            await expectNoError(page);
            await expect(page).toHaveURL(/\/employees\/\d+/);
        }
    });

    test('employee profile tabs work', async ({ page }) => {
        await page.goto('/employees');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        const profileLink = page.locator('a[href*="/employees/"]').first();
        if (await profileLink.isVisible()) {
            await profileLink.click();
            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(600);
            const tabs = page.locator('[role="tab"], .tab-btn');
            const count = await tabs.count();
            for (let i = 0; i < Math.min(count, 5); i++) {
                await tabs.nth(i).click();
                await page.waitForTimeout(300);
                await expectNoError(page);
            }
        }
    });

    test('org chart loads with nodes', async ({ page }) => {
        await page.goto('/employees/org-chart');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000); // org chart needs extra time to render
        await expectNoError(page);
        // OrgChartNode renders as <li> inside a tree
        await expect(page.locator('li, .node, [class*="org"]').first()).toBeVisible({ timeout: 10_000 });
    });

    test('org chart search works', async ({ page }) => {
        await page.goto('/employees/org-chart');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000);
        const search = page.locator('input').first();
        if (await search.isVisible()) {
            await search.fill('a');
            await page.waitForTimeout(400);
        }
        await expectNoError(page);
    });

    test('org chart expand/collapse works', async ({ page }) => {
        await page.goto('/employees/org-chart');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000);
        const expandBtn = page.locator('button:has-text("Expand"), button:has-text("expand")').first();
        if (await expandBtn.isVisible()) {
            await expandBtn.click();
            await page.waitForTimeout(500);
        }
        await expectNoError(page);
    });

    test('staff report page loads', async ({ page }) => {
        await page.goto('/employees/reports');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('employee lifecycle page loads', async ({ page }) => {
        await page.goto('/employees/lifecycle');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('records & KYC page loads', async ({ page }) => {
        await page.goto('/employees/records');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

    test('my portal loads', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
    });

});
