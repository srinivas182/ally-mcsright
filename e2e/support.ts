import { createHmac } from 'node:crypto';

import { expect, type Page } from '@playwright/test';

export const PORT = Number(process.env.E2E_PORT ?? 80);
const port = PORT === 80 ? '' : `:${PORT}`;
export const APP = `http://e2e.rightally.app${port}`;
export const SITE = `http://e2e.rightally.site${port}`;
export const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password-123';

/** The owner's two-step secret from `php artisan e2e:seed` (a test secret, never used anywhere real). */
const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** The six-digit code an authenticator app would show now (RFC 6238, 30-second steps, SHA-1). */
export function totp(secret = TOTP_SECRET, at = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '')) {
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const h = createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    const n =
        ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];

    return String(n % 1_000_000).padStart(6, '0');
}

let lastCode = '';

export async function signIn(
    page: Page,
    who: 'agent' | 'owner',
): Promise<void> {
    await page.goto(`${APP}/login`);
    await page.getByLabel('Email address').fill(`e2e-${who}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    if (who === 'owner') {
        await expect(page).toHaveURL(/two-factor-challenge/);
        // Like a person with an authenticator app: type the current code; if it was just used or just expired,
        // wait for the next one and try again.
        for (
            let attempt = 0;
            attempt < 3 && /two-factor-challenge/.test(page.url());
            attempt++
        ) {
            while (Date.now() % 30_000 > 25_000) {
                await page.waitForTimeout(500);
            }
            let code = totp();
            while (code === lastCode) {
                await page.waitForTimeout(1000);
                code = totp();
            }
            lastCode = code;
            await page.locator('input[name="code"]').fill(code);
            await page.getByRole('button', { name: 'Continue' }).click();
            await page
                .waitForURL((u) => !/two-factor-challenge/.test(u.toString()), {
                    timeout: 8_000,
                })
                .catch(() => undefined);
        }
    }
    await expect(page).not.toHaveURL(/login|two-factor/);
}
