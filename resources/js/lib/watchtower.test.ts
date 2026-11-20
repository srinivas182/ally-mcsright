import { describe, expect, it } from 'vitest';

import { pagePattern } from './watchtower';

describe('pagePattern', () => {
    it('takes ids out of the address', () => {
        expect(
            pagePattern('/contacts/123/deals/01J0ABCDEFGHJKMNPQRSTVWXYZ'),
        ).toBe('/contacts/{id}/deals/{id}');
        expect(pagePattern('/settings/blog-writer')).toBe(
            '/settings/blog-writer',
        );
    });
});
