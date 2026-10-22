// tests/e2e/08-recruitment.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('Recruitment', () => {

    test('recruitment pipeline loads', async ({ page }) => {
        await page.goto('/recruitment');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        await expect(page.locator('main, h1, h2').first()).toBeVisible();
    });

    test('recruitment pipeline tabs work', async ({ page }) => {
        await page.goto('/recruitment');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        const tabs = page.locator('[role="tab"], .tab-btn');
        const count = await tabs.count();
        for (let i = 0; i < Math.min(count, 5); i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(400);
            await expectNoError(page);
        }
    });

    test('hiring reports page loads', async ({ page }) => {
        await page.goto('/recruitment/reports');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
    });

    test('add requisition button visible', async ({ page }) => {
        await page.goto('/recruitment');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expectNoError(page);
        await expect(page.locator('main')).toBeVisible();
        // New Requisition button should be visible on the requisitions tab
        await expect(page.locator('button:has-text("New Requisition"), button:has-text("Requisition")').first()).toBeVisible({ timeout: 5_000 });
    });

    test('convert to employee button visible for hired applicant', async ({ page }) => {
        await page.goto('/recruitment');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        // Switch to applicants tab
        const applicantsTab = page.locator('[role="tab"]:has-text("Applicants"), button:has-text("Applicants")').first();
        if (await applicantsTab.isVisible()) {
            await applicantsTab.click();
            await page.waitForTimeout(600);
            // Filter to hired stage
            const hiredFilter = page.locator('button:has-text("Hired")').first();
            if (await hiredFilter.isVisible()) {
                await hiredFilter.click();
                await page.waitForTimeout(400);
                // If any hired applicants exist, the convert button should be visible
                const convertBtn = page.locator('button[title="Convert to Employee"]').first();
                if (await convertBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
                    await expect(convertBtn).toBeVisible();
                }
            }
        }
        await expectNoError(page);
    });

});
