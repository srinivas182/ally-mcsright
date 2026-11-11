<?php

declare(strict_types=1);

namespace Modules\Crm\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Tag;

/**
 * Brokerage-wide labels ("first-time buyer", "investor"); matching is case-insensitive.
 */
final class Tags
{
    public function attach(Contact $contact, string $name): Tag
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            throw ValidationException::withMessages(['tag' => 'Tags are 1 to 40 characters.']);
        }

        $tag = Tag::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Tag::query()->create(['name' => $name]);

        if (! $contact->tags()->whereKey($tag->id)->exists()) {
            $contact->tags()->attach($tag->id, ['tenant_id' => $contact->tenant_id]);
        }

        return $tag;
    }

    public function detach(Contact $contact, Tag $tag): void
    {
        $contact->tags()->detach($tag->id);
    }
}
