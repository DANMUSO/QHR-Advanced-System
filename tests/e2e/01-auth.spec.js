// tests/e2e/01-auth.spec.js
import { test, expect } from '@playwright/test';

// All auth tests need a fresh context with no saved session
test.describe('Authentication', () => {

    test('login page loads', async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(800);
        await expect(page.locator('input[type="email"], input[name="email"]')).toBeVisible({ timeout: 8_000 });
        await ctx.close();
    });

    test('wrong password shows error', async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await page.fill('input[type="email"], input[name="email"]', 'admin@hr.test');
        await page.fill('input[type="password"], input[name="password"]', 'wrongpassword');
        await page.click('button[type="submit"]');
        // Error can be inline field error or a toast
        await expect(
            page.locator('[class*="error"], [role="alert"]')
                .or(page.locator('p:has-text("invalid"), p:has-text("incorrect"), p:has-text("credentials")'))
        ).toBeVisible({ timeout: 8_000 });
        await ctx.close();
    });

    test('admin login redirects to dashboard', async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await page.fill('input[type="email"], input[name="email"]', 'admin@hr.test');
        await page.fill('input[type="password"], input[name="password"]', 'Password@123');
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('login'), { timeout: 15_000 });
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('main')).toBeVisible({ timeout: 8_000 });
        await ctx.close();
    });

    test('employee login works', async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await page.fill('input[type="email"], input[name="email"]', 'employee@hr.test');
        await page.fill('input[type="password"], input[name="password"]', 'Password@123');
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('login'), { timeout: 15_000 });
        await expect(page).not.toHaveURL(/login/);
        await ctx.close();
    });

    test('logout works', async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(600);
        await page.fill('input[type="email"], input[name="email"]', 'admin@hr.test');
        await page.fill('input[type="password"], input[name="password"]', 'Password@123');
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('login'), { timeout: 15_000 });
        await page.waitForTimeout(800);

        // Sign out is in the user avatar dropdown in the sidebar — click the avatar/name button first
        const userMenuBtn = page.locator('button[aria-label*="user" i], button[aria-label*="account" i], [data-testid="user-menu"]')
            .or(page.locator('aside button').last())
            .first();
        if (await userMenuBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await userMenuBtn.click();
            await page.waitForTimeout(300);
        }
        // Now find the sign out button (may be in dropdown or directly visible)
        const signOut = page.locator('button:has-text("Sign out"), a:has-text("Sign out"), button:has-text("Logout"), a:has-text("Logout")').first();
        await expect(signOut).toBeVisible({ timeout: 5_000 });
        await signOut.click();
        await page.waitForURL(/login/, { timeout: 10_000 });
        await expect(page).toHaveURL(/login/);
        await ctx.close();
    });

});
