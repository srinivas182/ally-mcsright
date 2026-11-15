<?php

declare(strict_types=1);

namespace Modules\Ads\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Ads\ListingAds;
use Modules\Ads\Social\SocialPosts;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;

#[Description('Post (now or at a time) to the person\'s connected Facebook Pages, Instagram, LinkedIn and Google Business Profile. Optionally start from one of their listings (its photo and website page are added). Fair Housing checked; their name and brokerage are added. Shows where it goes and the exact words first. If nothing is connected, ask them to connect facebook_pages, linkedin or google_business.')]
final class SocialPostTool extends RightAllyTool
{
    protected string $name = 'social_post';

    protected bool $writes = true;

    protected bool $confirmFirst = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The post, in the person\'s voice. Leave empty with a listing to use a "Just listed" line.'),
            'listing' => $schema->string()->description('One of their listings: its id, or words from its address'),
            'link' => $schema->string()->description('An https:// link to add (a listing\'s website page is added automatically)'),
            'networks' => $schema->array()->items($schema->string()->enum(array_keys(SocialPosts::CHANNELS)))->description('Where: facebook, instagram, linkedin, google. Default: everywhere connected.'),
            'when' => $schema->string()->description('Local date and time like 2026-06-02T09:00, or empty for now'),
        ];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<mixed> */
    protected function preview(Request $request, User $user): array
    {
        $in = $this->input($request, $user);
        $names = collect(app(SocialPosts::class)->places($user))->whereIn('key', $in['places'])->map(fn (array $p): string => SocialPosts::CHANNELS[$p['channel']].': '.$p['name'])->values()->all();

        return ['action' => 'Social post', 'where' => $names, 'when' => $in['post_at'] === '' ? 'Now' : $in['post_at'], 'text' => $in['text'], 'link' => $in['link'] ?: null,
            'then' => 'Your name and brokerage are added at the end. Results show under Grow → Social posts.'];
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $post = app(SocialPosts::class)->schedule($user, $this->input($request, $user));

        return ['scheduled_for' => $post->post_at->toIso8601String(), 'places' => $post->targets()->count(), 'page' => '/social'];
    }

    /** @return array{text: string, link: string, listing: string|null, places: list<string>, post_at: string} */
    private function input(Request $request, User $user): array
    {
        $social = app(SocialPosts::class);
        $places = $social->places($user);
        if ($places === []) {
            throw ValidationException::withMessages(['places' => 'No social accounts are connected. Connect Facebook Pages, LinkedIn or Google Business Profile under Apps first.']);
        }
        $networks = array_values(array_intersect(array_map('strval', (array) $request->get('networks', [])), array_keys(SocialPosts::CHANNELS)));
        $chosen = array_values(array_map(fn (array $p): string => $p['key'], array_filter($places, fn (array $p): bool => $networks === [] || in_array($p['channel'], $networks, true))));
        $listing = null;
        $words = mb_strtolower(trim((string) $request->get('listing', '')));
        if ($words !== '') {
            $match = collect(app(ListingAds::class)->listings($user))->first(fn (array $l): bool => mb_strtolower($l['ulid']) === $words || str_contains(mb_strtolower($l['label']), $words));
            $listing = $match !== null ? (string) $match['ulid'] : throw ValidationException::withMessages(['listing' => 'That isn\'t one of your listings.']);
        }
        $text = trim((string) $request->get('text', ''));
        if ($text === '' && $listing !== null) {
            $text = $social->fromListing($user, $listing)['text'];
        }

        return ['text' => $text, 'link' => trim((string) $request->get('link', '')), 'listing' => $listing, 'places' => $chosen, 'post_at' => trim((string) $request->get('when', ''))];
    }
}
