import { describe, expect, it } from 'vite-plus/test';

import { guideFor, HQ_PREFIX } from '@/components/menu/guides';
import { HQ_GUIDES, HQ_PAGE_HINTS } from '@/components/menu/hq-guides';

// HQ4: the HQ menus (NavigationRegistry::HQ_MENUS); each has a guide.
const HQ_MENUS = [
    'Today',
    'Customers',
    'Support',
    'Billing',
    'Websites and MLS',
    'Movewise',
    'Products',
    'AI',
    'Connections',
    'System',
    'Settings',
];

describe('HQ guides', () => {
    it('has a guide for every HQ menu, found with the HQ prefix only', () => {
        for (const menu of HQ_MENUS) {
            expect(guideFor(`${HQ_PREFIX}${menu}`, 'hq_staff')).toBe(
                HQ_GUIDES[menu],
            );
            expect(HQ_GUIDES[menu]?.start).toHaveLength(3);
        }
        expect(guideFor(`${HQ_PREFIX}Dashboard`, 'hq_staff')).toBeNull();
    });

    it('points every AI marker at a lesson of its own menu guide, with no assistant example', () => {
        const lessons = Object.values(HQ_GUIDES).flatMap((g) =>
            g.lessons.map((l) => l.id),
        );
        for (const [href, hint] of Object.entries(HQ_PAGE_HINTS)) {
            expect(href.startsWith('/hq/')).toBe(true);
            if (hint.ai) {
                expect(lessons).toContain(hint.ai.lesson);
                expect(hint.ai.example).toBeUndefined();
            }
        }
    });

    it('only links lessons to HQ pages', () => {
        for (const guide of Object.values(HQ_GUIDES)) {
            for (const lesson of guide.lessons) {
                if (lesson.try) {
                    expect(lesson.try.href.startsWith('/hq/')).toBe(true);
                }
            }
        }
    });
});
