import axe from 'axe-core';
import { expect, type Page, test } from '@playwright/test';

import { APP, SITE, signIn } from './support';

/*
 * E2E2 (specs/core/15): the journeys added in the second batch (sprints FL1 to LN4), in a real browser. Each is
 * what a person does, in their words. Pages are also checked for serious accessibility problems (axe), since many
 * of our agents are 50 and over.
 */

/** Fails the journey when the page has a serious or critical accessibility problem. */
async function accessible(page: Page): Promise<void> {
    await page.addScriptTag({ content: axe.source });
    const result = await page.evaluate(async () => {
        const axe = (
            window as unknown as {
                axe: {
                    run: (
                        c: unknown,
                        o: unknown,
                    ) => Promise<{
                        violations: {
                            id: string;
                            impact: string | null;
                            nodes: unknown[];
                        }[];
                    }>;
                };
            }
        ).axe;
        const r = await axe.run(document, {
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
            resultTypes: ['violations'],
        });

        return r.violations
            .filter((v) => v.impact === 'serious' || v.impact === 'critical')
            .map((v) => `${v.id} (${v.nodes.length})`);
    });
    expect(result, `accessibility problems on ${page.url()}`).toEqual([]);
}

/** Adds a contact the way an agent does, from the Contacts page. */
async function addContact(
    page: Page,
    first: string,
    extra: { email?: string; phone?: string },
): Promise<void> {
    await page.goto(`${APP}/contacts`);
    await page.getByRole('button', { name: 'Add contact' }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('First name').fill(first);
    if (extra.email) {
        await dialog.getByLabel('Email').fill(extra.email);
    }
    if (extra.phone) {
        await dialog.getByLabel('Mobile phone').fill(extra.phone);
    }
    await dialog.getByRole('button', { name: 'Add contact' }).click();
    await expect(dialog).toBeHidden();
}

test('RightAlly installs like an app and the search bar finds a contact @phone', async ({
    page,
}) => {
    const manifest = await page.goto(`${APP}/manifest.webmanifest`);
    expect(manifest?.ok()).toBeTruthy();
    expect(JSON.parse((await manifest?.text()) ?? '{}').display).toBe(
        'standalone',
    );

    await signIn(page, 'agent');
    const name = `Searchy${Date.now() % 100000}`;
    await addContact(page, name, {
        email: `${name.toLowerCase()}@example.test`,
    });
    const found = await page.evaluate(async (q) => {
        const r = await fetch(`/search?q=${q}`, {
            headers: { Accept: 'application/json' },
        });

        return r.ok ? JSON.stringify(await r.json()) : `status ${r.status}`;
    }, name);
    expect(found).toContain(name);
});

test('the owner sees outside services, features and approvals in plain words', async ({
    page,
}) => {
    await signIn(page, 'owner');
    await page.goto(`${APP}/settings/services`);
    await expect(page.getByText('AI voice calls').first()).toBeVisible();
    await expect(page.getByText('Bot protection').first()).toBeVisible();
    await accessible(page);
    await page.goto(`${APP}/settings/features`);
    await expect(page.locator('h1').first()).toBeVisible();
    await page.goto(`${APP}/settings/approvals`);
    await expect(page.locator('h1').first()).toBeVisible();
    await page.goto(`${APP}/settings/brand-voice`);
    await expect(
        page.getByRole('heading', { name: 'Brand voice', level: 1 }),
    ).toBeVisible();
    await accessible(page);
});

test('a visitor asks the brokerage what data it holds, and is asked to confirm by email @phone', async ({
    page,
}) => {
    await page.goto(`${SITE}/privacy`);
    const form = page.locator('form[action="/_privacy"]');
    await form.getByLabel('Your name').fill('Pat Visitor');
    await form
        .getByLabel('Your email')
        .fill(`privacy${Date.now()}@example.test`);
    await form.getByRole('button', { name: 'Send my request' }).click();
    await expect(page).toHaveURL(/sent=1/);
    await expect(page.getByText('We emailed you a link')).toBeVisible();
});

test('an agent forwards contracts to their own Deal-IQ address and adds an assistant', async ({
    page,
}) => {
    await signIn(page, 'agent');
    await page.goto(`${APP}/deals`);
    await expect(page.getByText(/dealiq-[A-Za-z0-9]{40}@/)).toBeVisible();
    await page.goto(`${APP}/my-assistants`);
    await expect(page.locator('h1').first()).toBeVisible();
    await accessible(page);
});

test('an agent opens the call queue and sees what to say when someone is busy', async ({
    page,
}) => {
    await signIn(page, 'agent');
    const n = Date.now() % 10000;
    await addContact(page, `Caller${n}`, {
        phone: `51255${String(n).padStart(5, '0')}`,
    }); // a new number each run
    await page.goto(`${APP}/outreach/calls?list=new-leads`);
    const busy = page.getByRole('button', { name: /I'm busy right now/ });
    if (await busy.count()) {
        await busy.click();
        await expect(page.getByText(/When is a better time/)).toBeVisible();
    }
});

test('an agent starts an accountability pod with the owner and checks in', async ({
    page,
}) => {
    await signIn(page, 'agent');
    await page.goto(`${APP}/learn/pods`);
    await page.getByLabel('Name').fill(`Pod ${Date.now() % 100000}`);
    await page.getByLabel('Erin Owner').check();
    await page.getByRole('button', { name: 'Start the pod' }).click();
    await expect(page).toHaveURL(/\/learn\/pods\/[0-9A-Z]{26}/);
    await page.getByLabel('This week’s goal').fill('Call 20 past clients');
    await page.getByRole('button', { name: 'Save my check-in' }).click();
    await expect(page.getByText('Call 20 past clients').first()).toBeVisible();
    await accessible(page);
});

test('the owner opens bank reconciliation and appointments with their recording panel', async ({
    page,
}) => {
    await signIn(page, 'owner');
    await page.goto(`${APP}/finance/bank`);
    await expect(
        page.getByRole('heading', { name: 'Bank reconciliation' }),
    ).toBeVisible();
    await page.goto(`${APP}/appointments`);
    await expect(
        page.getByRole('heading', { name: 'Appointments' }),
    ).toBeVisible();
    await expect(
        page.getByText('My coaching from recorded appointments'),
    ).toBeVisible();
    await accessible(page);
});
