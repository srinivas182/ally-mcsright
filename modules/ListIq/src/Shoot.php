<?php

declare(strict_types=1);

namespace Modules\ListIq;

/**
 * The guided photo shoot (LI1, specs/listings/02): the rooms to shoot, in the order buyers like to see them, with a
 * plain tip for each. Phones take good listing photos when they're held level, in landscape, with the lights on.
 */
final class Shoot
{
    /** room => [label, tip, suggested photos] */
    public const ROOMS = [
        'front' => ['Front of the home', 'Stand across the street, phone level at chest height, whole home in the frame. Mid-morning or late afternoon light.', 2],
        'living' => ['Living room', 'Shoot from the doorway into the corner, lights and lamps on, blinds open.', 2],
        'kitchen' => ['Kitchen', 'Clear the counters. Shoot from the corner to show the island or counters and the appliances.', 2],
        'dining' => ['Dining', 'Table set simply or cleared; chairs pushed in.', 1],
        'family' => ['Family or bonus room', 'Show how the room connects to the rest of the home.', 1],
        'primary_bed' => ['Primary bedroom', 'Bed made, nightstands cleared, shoot from the door corner.', 1],
        'primary_bath' => ['Primary bathroom', 'Toilet lid down, towels folded, shoot from the doorway.', 1],
        'bedroom' => ['Other bedrooms', 'One photo per bedroom from the doorway.', 2],
        'bathroom' => ['Other bathrooms', 'Doorway, level, lights on.', 1],
        'office' => ['Office', 'Desk tidy, screen off.', 1],
        'laundry' => ['Laundry', 'Only if it adds value.', 0],
        'garage' => ['Garage', 'Doors open, cars out.', 0],
        'backyard' => ['Backyard', 'Whole yard from the house, then from the back corner toward the house.', 2],
        'pool' => ['Pool', 'Covers off, toys out of sight.', 0],
        'view' => ['View or community', 'Views, the street, community amenities.', 1],
        'other' => ['Other', 'Anything that makes the home special.', 0],
    ];

    public const TIPS = ['Hold the phone sideways (landscape) and level: vertical lines should be straight.', 'Turn on every light and open the blinds.',
        'Use the main (1×) lens, or 0.5× only in small rooms.', 'Take a few of each room; you choose the best later.', 'No people, pets or family photos in the frame.'];

    public static function label(string $room): string
    {
        return self::ROOMS[$room][0] ?? 'Other';
    }

    public static function order(string $room): int
    {
        $i = array_search($room, array_keys(self::ROOMS), true);

        return $i === false ? 99 : (int) $i;
    }
}
