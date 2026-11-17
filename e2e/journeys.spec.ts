import { expect, test } from '@playwright/test';

import { APP, SITE, signIn } from './support';

/*
 * E2E1 (specs/core/15): the journeys a brokerage can't live without, in a real browser. Each one is what a
 * person does, in their words; if one breaks, something important broke.
 */

test('an agent signs in and opens a new deal, which shows on the deals board', async ({
    page,
}) => {
    await signIn(page, 'agent');
    await page.goto(`${APP}/deals`);
    await page.getByRole('button', { name: 'New deal' }).click();
    const street = `${Date.now() % 100000} Playwright Ln`;
    await page.getByLabel('Street address').fill(street);
    await page.getByLabel('City').fill('Austin');
    await page.getByLabel('State').fill('TX');
    await page.getByLabel('Price ($)').fill('425000');
    await page.getByRole('button', { name: 'Create deal' }).click();
    await page.goto(`${APP}/deals`);
    await expect(page.getByText(street)).toBeVisible();
});

test('the owner signs in with two-step verification and makes text larger, and it stays larger', async ({
    page,
}) => {
    await signIn(page, 'owner');
    await page.goto(`${APP}/settings/appearance`);
    await page.getByRole('button', { name: 'Largest', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-text', 'largest');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-text', 'largest');
    await page.getByRole('button', { name: 'Standard', exact: true }).click();
    await expect(page.locator('html')).not.toHaveAttribute(
        'data-text',
        'largest',
    );
});

test('a visitor reads the brokerage website and sends an enquiry, which reaches the brokerage @phone', async ({
    page,
    browser,
}, testInfo) => {
    await page.goto(`${SITE}/`);
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
    let form = page.locator('form[action="/_lead"]').first();
    if ((await form.count()) === 0) {
        await page.goto(`${SITE}/contact`);
        form = page.locator('form[action="/_lead"]').first();
    }
    const email = `visitor${Date.now()}@example.test`;
    await form.getByLabel('Name', { exact: true }).fill('Pat Visitor');
    await form.getByLabel('Email', { exact: true }).fill(email);
    await form
        .getByLabel('Message', { exact: true })
        .fill('Hello, we would like to see homes this weekend.');
    await page.waitForTimeout(3500); // people take a few seconds; faster is treated as a robot
    await form.getByRole('button').click();
    await expect(page).toHaveURL(/sent=1/);
    if (testInfo.project.name === 'phone') {
        return; // the phone run checks the visitor's side; the desktop run checks the brokerage receives it
    }

    const owner = await (await browser.newContext()).newPage();
    await signIn(owner, 'owner');
    await owner.goto(`${APP}/leads`);
    await expect(owner.getByText('Pat Visitor').first()).toBeVisible();
});

test('the brokerage website has an accessibility statement and a sitemap search engines can read', async ({
    page,
}) => {
    const statement = await page.goto(`${SITE}/accessibility`);
    expect(statement?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'Accessibility',
    );
    const sitemap = await page.goto(`${SITE}/sitemap.xml`);
    expect(sitemap?.status()).toBe(200);
    expect(await sitemap?.text()).toContain('<urlset');
});

test('an agent sets up a referral network profile once the brokerage switches it on', async ({
    page,
    browser,
}) => {
    const owner = await (await browser.newContext()).newPage();
    await signIn(owner, 'owner');
    await owner.goto(`${APP}/settings/network`);
    const on = owner.getByLabel('Use the referral network');
    if (!(await on.isChecked())) {
        await on.check();
        await owner.getByRole('button', { name: 'Save' }).click();
    }

    await signIn(page, 'agent');
    await page.goto(`${APP}/network?tab=profile`);
    await page.getByLabel(/Where you work/).fill('Austin, TX\n78704');
    await page.getByLabel('Licence state').fill('TX');
    await page.getByRole('button', { name: 'Save my profile' }).click();
    await expect(page.getByText('Your Referral Trust Score')).toBeVisible();
});
