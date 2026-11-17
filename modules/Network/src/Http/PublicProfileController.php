<?php

declare(strict_types=1);

namespace Modules\Network\Http;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Network\Profiles;

/**
 * An agent's public referral page (AR1, specs/network/01), only when the agent chose "public". Search and answer
 * engines get a RealEstateAgent with the areas served and languages; never an email address, and the phone only if
 * the agent shares it.
 */
final class PublicProfileController
{
    public function show(string $code): Response
    {
        $p = DB::connection(Connections::control())->table('network_profiles')->where('code', $code)->where('public', true)->where('status', 'active')->first();
        abort_if($p === null, 404);
        $card = Profiles::card($p);
        $url = 'https://'.config('core.hq_host').'/agents/'.$card['code'];
        $areas = implode(', ', $card['areas']);
        $title = "{$card['name']}, real estate agent in {$card['areas'][0]} | {$card['brokerage']}";
        $description = mb_substr("{$card['name']} of {$card['brokerage']} helps ".mb_strtolower($card['sides'])." in {$areas}. Languages: ".implode(', ', $card['languages']).'.', 0, 160);
        $ld = array_filter(['@context' => 'https://schema.org', '@type' => 'RealEstateAgent', 'name' => $card['name'], 'url' => $url, 'description' => $card['bio'],
            'telephone' => $card['phone'], 'worksFor' => ['@type' => 'RealEstateAgent', 'name' => $card['brokerage']],
            'areaServed' => array_map(fn (string $a): array => ['@type' => 'Place', 'name' => $a], $card['areas']), 'knowsLanguage' => $card['languages']]);

        return response()->view('network::public', ['title' => $title, 'description' => $description, 'canonical' => $url, 'indexable' => true,
            'jsonld' => (string) json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), 'card' => $card,
            'section' => 'Referral network', 'home' => '/start', 'signup' => false])->header('Cache-Control', 'public, max-age=300');
    }
}
