// tests/e2e/13-roles.spec.js
import { test, expect } from '@playwright/test';

test.use({ storageState: undefined });

// Login helper that works in a fresh context
async function loginAndGoto(browser, email, path) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(500);
    await page.fill('input[type="email"], input[name="email"]', email);
    await page.fill('input[type="password"], input[name="password"]', 'Password@123');
    await page.click('button[type="submit"]');
    await page.waitForURL(url => !url.pathname.includes('login'), { timeout: 15_000 });
    await page.goto(path);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
    return { page, ctx };
}

test.describe('Role-based access', () => {

    test('HR admin sees leave management', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'hr@hr.test', '/leave/manage');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('HR admin sees attendance management', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'hr@hr.test', '/attendance/manage');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('supervisor sees leave reports', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'supervisor@hr.test', '/leave/reports');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('supervisor sees attendance management', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'supervisor@hr.test', '/attendance/manage');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('finance sees payroll', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'finance@hr.test', '/payroll');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('payroll officer sees payroll', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'payroll@hr.test', '/payroll');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('employee can access portal', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'employee@hr.test', '/portal');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible();
        await ctx.close();
    });

    test('employee cannot access payroll runs', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'employee@hr.test', '/payroll');
        // The SPA router should redirect away from /payroll for employees
        // Either the URL changed (redirected to dashboard/portal) or payroll-specific content is absent
        const finalUrl = page.url();
        const isOnPayroll = finalUrl.includes('/payroll');
        if (isOnPayroll) {
            // If still on /payroll, payroll run table must not be visible
            await expect(page.locator('button:has-text("New Run"), th:has-text("Gross"), th:has-text("Net Pay")')).toHaveCount(0);
        } else {
            // Redirected away — correct behaviour
            await expect(page.locator('main')).toBeVisible();
        }
        await ctx.close();
    });

    test('employee cannot access org settings', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'employee@hr.test', '/organization/settings');
        // Should be redirected or show no settings content
        const finalUrl = page.url();
        if (finalUrl.includes('/organization/settings')) {
            // If still on settings, settings form must not be visible
            await expect(page.locator('button:has-text("Save settings"), input[name*="setting"]')).toHaveCount(0);
        } else {
            await expect(page.locator('main')).toBeVisible();
        }
        await ctx.close();
    });

    test('employee cannot access employee management', async ({ browser }) => {
        const { page, ctx } = await loginAndGoto(browser, 'employee@hr.test', '/employees');
        // Employee role has no employees.view — should be redirected
        const finalUrl = page.url();
        if (finalUrl.includes('/employees') && !finalUrl.includes('/portal')) {
            // If still on /employees, the Add Employee button must not be visible
            await expect(page.locator('button:has-text("Add Employee"), button:has-text("New Employee")')).toHaveCount(0);
        }
        await ctx.close();
    });

});
