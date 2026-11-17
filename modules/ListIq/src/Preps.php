<?php

declare(strict_types=1);

namespace Modules\ListIq;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\FairHousing;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\Transactions\Models\Deal;
use ZipArchive;

/**
 * Listings being made ready (LI1, specs/listings/02): the facts and the agent's notes, the readiness checklist, and
 * the MLS-ready package (photos in order with captions, the description in English and Spanish, and the list of
 * virtually staged photos to disclose).
 */
final class Preps
{
    public const MIN_PHOTOS = 12;

    public const MANUAL = ['disclosures' => 'Seller disclosures signed', 'sign' => 'Sign and lockbox ordered'];

    public static function canSee(User $user, Prep $prep): bool
    {
        return $prep->owner_user_id === $user->id || $user->isKind(UserKind::Owner);
    }

    /**
     * @param  array<string, mixed>  $in  deal (ulid) or street, city, state, zip; beds, baths, sqft, year_built, price
     */
    public function create(User $by, array $in): Prep
    {
        $deal = isset($in['deal']) && is_string($in['deal']) && $in['deal'] !== '' ? Deal::query()->where('ulid', $in['deal'])->first() : null;
        $street = trim((string) ($deal->street ?? $in['street'] ?? ''));
        if ($street === '') {
            throw ValidationException::withMessages(['street' => 'Enter the street address, or start from a listing deal.']);
        }
        $prep = Prep::query()->create(['owner_user_id' => $by->id, 'deal_id' => $deal?->id, 'street' => mb_substr($street, 0, 150), 'city' => $deal->city ?? ($in['city'] ?? null),
            'state' => strtoupper((string) ($deal->state_code ?? $in['state'] ?? '')) ?: null, 'zip' => $deal->postal_code ?? ($in['zip'] ?? null),
            'facts' => self::facts($in + ['price' => $deal !== null ? intdiv($deal->price_cents, 100) : null]), 'manual' => []]);
        app(AuditLogger::class)->record('listiq.listing.created', $prep);

        return $prep;
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function update(Prep $prep, array $in): void
    {
        if (isset($in['facts']) && is_array($in['facts'])) {
            $prep->facts = self::facts($in['facts']);
        }
        foreach (['notes' => 3000, 'showing' => 1500] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $prep->{$k} = $in[$k] !== null ? mb_substr(trim(strip_tags((string) $in[$k])), 0, $max) : null;
            }
        }
        if (isset($in['manual']) && is_array($in['manual'])) {
            $prep->manual = array_intersect_key(array_map(fn ($v): bool => (bool) $v, $in['manual']), self::MANUAL) + (array) $prep->manual;
        }
        if (isset($in['showing_mode']) && in_array($in['showing_mode'], ['approve', 'auto'], true)) {
            $prep->showing_mode = (string) $in['showing_mode'];
        }
        if (array_key_exists('access', $in)) {
            $prep->access = $in['access'] !== null && trim((string) $in['access']) !== '' ? mb_substr(trim((string) $in['access']), 0, 1000) : null;
        }
        if (array_key_exists('seller_note', $in)) {
            $prep->seller_note = $in['seller_note'] !== null && trim((string) $in['seller_note']) !== '' ? mb_substr(trim(strip_tags((string) $in['seller_note'])), 0, 2000) : null;
            $prep->seller_note_at = $prep->seller_note !== null ? CarbonImmutable::now() : null;
        }
        if (isset($in['remarks_limit']) && is_numeric($in['remarks_limit'])) {
            $prep->remarks_limit = max(250, min(5000, (int) $in['remarks_limit']));
        }
        if (array_key_exists('description', $in)) {
            $text = trim((string) $in['description']);
            if (mb_strlen($text) > $prep->remarks_limit) {
                throw ValidationException::withMessages(['description' => "Your MLS allows {$prep->remarks_limit} characters; this is ".mb_strlen($text).'.']);
            }
            $prep->description = $text !== '' ? $text : null;
        }
        if (array_key_exists('description_es', $in)) {
            $prep->description_es = trim((string) $in['description_es']) ?: null;
        }
        $prep->status = $this->ready($prep) ? 'ready' : 'preparing';
        $prep->save();
    }

    /**
     * What's left before the listing goes live.
     *
     * @return list<array{key: string, label: string, done: bool, auto: bool, hint: string}>
     */
    public function checklist(Prep $prep): array
    {
        $photos = Photo::query()->where('listing_id', $prep->id)->where('use', true)->where('status', 'ready')->get();
        $originals = $photos->where('kind', '!=', 'staged');
        $flags = $prep->description !== null ? FairHousing::flags($prep->description) : [];
        $manual = (array) $prep->manual;

        return [
            ['key' => 'front', 'label' => 'A photo of the front', 'done' => $photos->contains('room', 'front'), 'auto' => true, 'hint' => 'Buyers decide from the first photo.'],
            ['key' => 'photos', 'label' => 'At least '.self::MIN_PHOTOS.' photos', 'done' => $originals->count() >= self::MIN_PHOTOS, 'auto' => true, 'hint' => $originals->count().' so far.'],
            ['key' => 'description', 'label' => 'Description chosen', 'done' => $prep->description !== null, 'auto' => true, 'hint' => 'Write it, or pick one of the AI drafts.'],
            ['key' => 'fair_housing', 'label' => 'Description clear of Fair Housing wording', 'done' => $prep->description !== null && $flags === [], 'auto' => true,
                'hint' => $flags === [] ? 'Checked.' : 'Change: '.implode(', ', $flags).'.'],
            ['key' => 'showing', 'label' => 'Showing instructions', 'done' => trim((string) $prep->showing) !== '', 'auto' => true, 'hint' => 'Lockbox, notice needed, pets.'],
            ...array_map(fn (string $k, string $label): array => ['key' => $k, 'label' => $label, 'done' => (bool) ($manual[$k] ?? false), 'auto' => false, 'hint' => 'Tick when done.'],
                array_keys(self::MANUAL), self::MANUAL),
        ];
    }

    public function ready(Prep $prep): bool
    {
        return collect($this->checklist($prep))->every(fn (array $i): bool => $i['done']);
    }

    /** The MLS-ready package: photos in order, captions, descriptions and the staging disclosure. */
    public function package(Prep $prep): string
    {
        $zip = new ZipArchive;
        $file = tempnam(sys_get_temp_dir(), 'listiq').'.zip';
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $disk = Storage::disk('public');
        $captions = ['number,file,room,caption'];
        $staged = [];
        foreach (Photo::query()->where('listing_id', $prep->id)->where('use', true)->where('status', 'ready')->orderBy('position')->get() as $i => $p) {
            if ($p->path === null || ! $disk->exists($p->path)) {
                continue;
            }
            $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $name = "{$n}-{$p->room}".($p->kind === 'staged' ? '-virtually-staged' : '').'.jpg';
            $zip->addFromString('photos/'.$name, (string) $disk->get($p->path));
            $captions[] = implode(',', [$n, $name, $p->room, '"'.str_replace('"', "'", (string) $p->caption).'"']);
            if ($p->kind === 'staged') {
                $staged[] = "Photo {$n} ({$name}) is virtually staged; the original is included as an unstaged photo.";
            }
        }
        $zip->addFromString('captions.csv', implode("\n", $captions)."\n");
        $zip->addFromString('description.txt', (string) $prep->description);
        if ($prep->description_es !== null) {
            $zip->addFromString('description-es.txt', $prep->description_es);
        }
        $zip->addFromString('staging-disclosure.txt', $staged === [] ? "No photos are virtually staged.\n" : implode("\n", $staged)."\n");
        $zip->close();

        return $file;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, int|string|null>
     */
    private static function facts(array $in): array
    {
        $int = fn (string $k, int $min, int $max): ?int => isset($in[$k]) && is_numeric($in[$k]) && (int) $in[$k] >= $min && (int) $in[$k] <= $max ? (int) $in[$k] : null;

        return ['beds' => $int('beds', 0, 20), 'baths' => isset($in['baths']) && is_numeric($in['baths']) ? (string) round((float) $in['baths'], 1) : null, 'sqft' => $int('sqft', 100, 50_000),
            'year_built' => $int('year_built', 1700, (int) date('Y') + 2), 'price' => $int('price', 1000, 100_000_000),
            'features' => isset($in['features']) ? mb_substr(trim(strip_tags((string) $in['features'])), 0, 600) : null];
    }
}
