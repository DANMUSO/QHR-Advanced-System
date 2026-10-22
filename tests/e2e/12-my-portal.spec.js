// tests/e2e/12-my-portal.spec.js
import { test, expect } from '@playwright/test';
import { expectNoError } from './helpers.js';

test.describe('My Portal - Employee Self Service', () => {

    test('portal loads for admin', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        await expectNoError(page);
        await expect(page).toHaveURL(/portal/);
        await expect(page.locator('h1, h2, [role="tab"]').first()).toBeVisible({ timeout: 8_000 });
    });

    test('portal tabs all load without error', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        const tabs = page.locator('[role="tab"]');
        const count = await tabs.count();
        for (let i = 0; i < count; i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(500);
            await expectNoError(page);
        }
    });

    test('leave tab - apply button opens modal', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1200);
        // Tab label is exactly "Leave"
        const leaveTab = page.locator('[role="tab"]:has-text("Leave")').first();
        if (await leaveTab.isVisible()) {
            await leaveTab.click();
            await page.waitForTimeout(500);
        }
        const applyBtn = page.locator('button:has-text("Apply")').first();
        if (await applyBtn.isVisible()) {
            await applyBtn.click();
            await page.waitForTimeout(500);
            await expect(page).toHaveURL(/portal/);
            await expect(page.locator('[role="dialog"]').first()).toBeVisible({ timeout: 5_000 });
        }
    });

    test('documents tab loads with upload button', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        // Tab label is exactly "Documents"
        const docsTab = page.locator('[role="tab"]:has-text("Documents")').first();
        if (await docsTab.isVisible()) {
            await docsTab.click();
            await page.waitForTimeout(600);
            await expectNoError(page);
            await expect(page.locator('button:has-text("Upload")').first()).toBeVisible({ timeout: 5_000 });
        }
    });

    test('payslips tab loads', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        // Tab label is exactly "Payslips" (plural)
        const payslipTab = page.locator('[role="tab"]:has-text("Payslips")').first();
        if (await payslipTab.isVisible()) {
            await payslipTab.click();
            await page.waitForTimeout(500);
            await expectNoError(page);
        }
    });

    test('salary advance tab loads', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        // Tab label is "Salary Advance"
        const advanceTab = page.locator('[role="tab"]:has-text("Salary Advance")').first();
        if (await advanceTab.isVisible()) {
            await advanceTab.click();
            await page.waitForTimeout(500);
            await expectNoError(page);
            await expect(page.locator('button:has-text("Request Advance")').first()).toBeVisible({ timeout: 5_000 });
        }
    });

    test('goals tab loads', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        // Tab label is "Goals & KPIs"
        const goalsTab = page.locator('[role="tab"]:has-text("Goals")').first();
        if (await goalsTab.isVisible()) {
            await goalsTab.click();
            await page.waitForTimeout(500);
            await expectNoError(page);
        }
    });

    test('my requests tab loads', async ({ page }) => {
        await page.goto('/portal');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);
        // Tab label is "My Requests"
        const requestsTab = page.locator('[role="tab"]:has-text("My Requests")').first();
        if (await requestsTab.isVisible()) {
            await requestsTab.click();
            await page.waitForTimeout(500);
            await expectNoError(page);
        }
    });

});
