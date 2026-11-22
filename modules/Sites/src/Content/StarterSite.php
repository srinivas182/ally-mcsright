<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Illuminate\Support\Str;
use Modules\Listings\Samples\SampleHomes;
use Modules\Sites\Sections;

/**
 * TP2: the website a brokerage starts with, like a finished template: Home, Buy, Sell, Communities, About and Contact,
 * each built from sections with licensed demo photos, plus three blog posts. Everything is the brokerage's to edit in
 * the website builder. Nothing here claims anything about the brokerage that it hasn't said (no invented sales
 * figures, awards or reviews): the reviews section only ever shows its real Google or RealSatisfied reviews.
 */
final class StarterSite
{
    /**
     * The pages, in menu order.
     *
     * @param  array<string, mixed>  $info  name, area, state, tagline, about
     * @param  string|null  $template  the design (TP3: each starts with its own banner photo)
     * @return list<array{path: string, title: string, in_menu: bool, meta: string, sections: list<array<string, mixed>>}>
     */
    public static function pages(array $info, ?string $template = null): array
    {
        $name = trim((string) ($info['name'] ?? '')) ?: 'Our brokerage';
        $area = trim((string) ($info['area'] ?? '')) ?: 'your area';
        $places = self::places($info);
        $city = self::cityPhoto($info);
        $p = fn (string $group, int $n = 0): string => DemoPhotos::pick($group, $n);

        $faqBuy = [
            ['q' => 'How do I book a showing?', 'a' => 'Open any home and send us a message, or call us. An agent confirms a time that suits you, usually within the hour.'],
            ['q' => 'Do I need to be pre-approved before I look at homes?', 'a' => 'It helps. A pre-approval tells you what you can spend and shows sellers you are ready, so your offer is taken seriously. We can introduce you to trusted local lenders.'],
            ['q' => 'How much do I need for a down payment?', 'a' => 'It depends on the loan. Some loans need as little as 3 to 3.5 percent down, and some programs for first-time buyers help with the down payment. Your lender will tell you what fits you.'],
            ['q' => 'What does a buyer pay at closing?', 'a' => 'Closing costs are usually 2 to 5 percent of the price: loan fees, title insurance, inspections and prepaid taxes and insurance. We go through them with you before you make an offer.'],
        ];
        $faqSell = [
            ['q' => 'What is my home worth?', 'a' => 'Request a free valuation and one of our agents will prepare a price range from recent nearby sales, usually within one business day.'],
            ['q' => 'How long does it take to sell?', 'a' => "It depends on the price, the condition and the season. Homes priced right from day one sell fastest in {$area}. We share the latest days-on-market for your neighborhood when we meet."],
            ['q' => 'Should I make repairs before listing?', 'a' => 'Fix what a buyer would notice first: leaks, broken fixtures, peeling paint. Fresh paint and a deep clean usually pay for themselves. We tell you what is worth doing and what is not.'],
            ['q' => 'What does it cost to sell?', 'a' => 'You pay the agreed commission and your share of the closing costs. We give you an estimate of what you would take home before you decide to list.'],
        ];
        $cta = ['type' => 'cta', 'id' => 'talk', 'props' => ['title' => 'Talk to a local agent', 'body' => "Questions about buying or selling in {$area}? We are here to help, with no pressure.",
            'button' => 'Contact us', 'link' => '/contact']];
        $reviews = ['type' => 'testimonials', 'id' => 'reviews', 'props' => ['title' => 'What our clients say', 'from_reviews' => true, 'items' => []]];

        return [
            ['path' => '/', 'title' => 'Home', 'in_menu' => true, 'meta' => mb_substr((string) ($info['tagline'] ?? '') ?: "Homes for sale in {$area}, updated every few minutes, with local agents from {$name}.", 0, 300),
                'sections' => Sections::clean([
                    ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => "Find your place in {$area}", 'subhead' => (string) ($info['tagline'] ?? '') ?: "Every home for sale, updated every few minutes, with local experts from {$name}.",
                        'image' => self::heroPhoto($template), 'search' => true]],
                    ['type' => 'listings', 'id' => 'featured', 'props' => ['title' => 'Featured homes', 'sort' => 'price_desc', 'limit' => 6]],
                    ['type' => 'split', 'id' => 'sell', 'props' => ['title' => 'Selling? Start with what your home is worth', 'side' => 'left',
                        'body' => "Get a free price range from an agent who knows {$area}, based on what nearby homes have sold for. No pressure and no obligation.\n\nWhen you are ready, we price it right, present it beautifully and put it in front of serious buyers.",
                        'button' => "What's my home worth?", 'link' => '/home-value', 'image' => $p('exterior_family', 3)]],
                    ['type' => 'stats', 'id' => 'promise', 'props' => ['title' => '', 'items' => [
                        ['value' => '1 search', 'label' => 'for every home on the MLS'], ['value' => 'Minutes', 'label' => 'from a new listing to your alert'],
                        ['value' => 'Free', 'label' => 'home value report'], ['value' => '24/7', 'label' => 'answers about any home'],
                    ]]],
                    ['type' => 'neighbourhoods', 'id' => 'areas', 'props' => ['title' => "Explore {$area}", 'places' => $places]],
                    ['type' => 'banner', 'id' => 'banner', 'props' => ['title' => 'Find the home that fits your life', 'body' => 'Save your favorite homes, get alerts the moment something new comes on the market, and tour with a local agent.',
                        'button' => 'Search homes', 'link' => '/homes', 'image' => $p('living', 3)]],
                    $reviews,
                    ['type' => 'faq', 'id' => 'faq', 'props' => ['title' => 'Questions buyers and sellers ask', 'items' => [$faqBuy[0], $faqSell[0], [
                        'q' => "Which areas does {$name} serve?", 'a' => "We help buyers and sellers across {$area}. Tell us where you are looking and we will match you with an agent who knows it well.",
                    ]]]],
                    $cta,
                ])],
            ['path' => '/buy', 'title' => 'Buy', 'in_menu' => true, 'meta' => "How to buy a home in {$area} with {$name}: search every home for sale, get pre-approved, tour, make an offer and close.",
                'sections' => Sections::clean([
                    ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => "Buying a home in {$area}", 'subhead' => 'Search every home for sale, get alerts the moment new ones list, and have a local agent with you from the first tour to the keys.',
                        'image' => $p('living', 0), 'search' => true]],
                    ['type' => 'features', 'id' => 'steps', 'props' => ['title' => 'How buying with us works', 'items' => [
                        ['title' => '1. Get pre-approved', 'body' => 'Know what you can spend before you fall in love with a home. We introduce you to trusted local lenders.'],
                        ['title' => '2. Search and tour', 'body' => 'Save homes, get instant alerts and tour in person or by video with an agent who knows the neighborhood.'],
                        ['title' => '3. Make a strong offer', 'body' => 'We look at recent sales together and write an offer that wins without overpaying.'],
                        ['title' => '4. Inspect and close', 'body' => 'We line up inspections, negotiate repairs and keep every deadline on track until you have the keys.'],
                    ]]],
                    ['type' => 'listings', 'id' => 'new', 'props' => ['title' => 'New on the market', 'sort' => 'newest', 'limit' => 6]],
                    ['type' => 'split', 'id' => 'first-time', 'props' => ['title' => 'Buying for the first time?', 'side' => 'right',
                        'body' => "Buying your first home is a big step. We explain every stage in plain words, from pre-approval to closing day, and point you to programs for first-time buyers in {$area}.\n\nUse our calculators to see what a monthly payment looks like before you start.",
                        'button' => 'Try the payment calculator', 'link' => '/calculators', 'image' => $p('kitchen', 0)]],
                    ['type' => 'faq', 'id' => 'faq', 'props' => ['title' => 'Questions buyers ask', 'items' => $faqBuy]],
                    $cta,
                ])],
            ['path' => '/sell', 'title' => 'Sell', 'in_menu' => true, 'meta' => "Sell your home in {$area} with {$name}: a free home value report, professional marketing and an agent with you to closing.",
                'sections' => Sections::clean([
                    ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => "Sell your home in {$area}", 'subhead' => 'Know what it is worth, get it ready, and put it in front of serious buyers, with a local agent beside you.',
                        'image' => $p('exterior_family', 0), 'search' => false]],
                    ['type' => 'features', 'id' => 'how', 'props' => ['title' => 'How we sell your home', 'items' => [
                        ['title' => 'The right price', 'body' => 'A price range from recent nearby sales and what buyers are paying now, so your home sells for what it is worth.'],
                        ['title' => 'Ready for its close-up', 'body' => 'Advice on what to fix and what to leave, then professional photos that make buyers want to see it.'],
                        ['title' => 'Seen by serious buyers', 'body' => 'Your home on the MLS, the big home search websites and our own buyers list, the day it goes live.'],
                        ['title' => 'Negotiated to closing', 'body' => 'We handle offers, inspections and appraisals, and keep everything on track until it closes.'],
                    ]]],
                    ['type' => 'split', 'id' => 'prepare', 'props' => ['title' => 'Make the first showing count', 'side' => 'left',
                        'body' => "Buyers decide in the first few minutes. Simple things matter most: light, space and a clean, fresh look.\n\nWe walk through your home with you and give you a short list of what is worth doing before the photos.",
                        'button' => 'Book a walk-through', 'link' => '/contact', 'image' => $p('kitchen', 2)]],
                    ['type' => 'banner', 'id' => 'value', 'props' => ['title' => 'What is your home worth today?', 'body' => 'Get a free price range from recent nearby sales, prepared by a local agent.',
                        'button' => 'Get my home value', 'link' => '/home-value', 'image' => $p('exterior_luxury', 4)]],
                    ['type' => 'faq', 'id' => 'faq', 'props' => ['title' => 'Questions sellers ask', 'items' => $faqSell]],
                    $cta,
                ])],
            ['path' => '/communities', 'title' => 'Communities', 'in_menu' => true, 'meta' => "Neighborhoods and cities across {$area}: homes for sale, prices and what each place is like.",
                'sections' => Sections::clean([
                    ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => "Communities across {$area}", 'subhead' => 'See what is for sale, what homes cost and how fast they sell in each place, updated every day.',
                        'image' => $city, 'search' => true]],
                    ['type' => 'neighbourhoods', 'id' => 'areas', 'props' => ['title' => 'Where we help people buy and sell', 'places' => $places]],
                    ['type' => 'split', 'id' => 'local', 'props' => ['title' => 'Local knowledge, street by street', 'side' => 'right',
                        'body' => "Every neighborhood has its own feel: schools, commutes, parks and the kind of homes you will find there. Our agents live and work across {$area} and can tell you what the listings can't.",
                        'button' => 'Ask about a neighborhood', 'link' => '/contact', 'image' => $p('neighbourhood', 2)]],
                    ['type' => 'gallery', 'id' => 'photos', 'props' => ['title' => 'Life around here', 'images' => [$city, $p('neighbourhood', 0), $p('neighbourhood', 5), $p('exterior_coastal', 2), $p('backyard', 0), $p('neighbourhood', 6)]]],
                    $cta,
                ])],
            ['path' => '/about', 'title' => 'About', 'in_menu' => true, 'meta' => "About {$name}: local real estate agents helping people buy and sell homes in {$area}.",
                'sections' => Sections::clean([
                    ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => "About {$name}", 'subhead' => "Local agents helping people buy and sell homes in {$area}.", 'image' => $p('neighbourhood', 1), 'search' => false]],
                    ['type' => 'split', 'id' => 'story', 'props' => ['title' => 'Our story', 'side' => 'left',
                        'body' => (string) ($info['about'] ?? '') ?: "{$name} helps people buy and sell homes across {$area}. We know the neighborhoods, we answer quickly, and we tell you the truth about the market, even when it is not what you hoped to hear.\n\nTell your story here: when you started, who you help and what makes your team different.",
                        'button' => 'Meet our agents', 'link' => '/agents', 'image' => $p('living', 5)]],
                    ['type' => 'features', 'id' => 'values', 'props' => ['title' => 'What you can expect from us', 'items' => [
                        ['title' => 'Straight answers', 'body' => 'Honest advice on price, timing and condition, in plain words.'],
                        ['title' => 'Quick replies', 'body' => 'Questions answered the same day, and an assistant on our website day and night.'],
                        ['title' => 'Local knowledge', 'body' => "Agents who live and work across {$area}."],
                    ]]],
                    $reviews,
                    $cta,
                ])],
            ['path' => '/contact', 'title' => 'Contact', 'in_menu' => true, 'meta' => "Contact {$name}: talk to a local real estate agent about buying or selling in {$area}.",
                'sections' => Sections::clean([
                    ['type' => 'contact', 'id' => 'contact', 'props' => ['title' => 'Contact us', 'body' => 'Send a message and an agent will get back to you shortly, usually within the hour during the day.']],
                    ['type' => 'faq', 'id' => 'faq', 'props' => ['title' => 'Before you write', 'items' => [$faqBuy[0], $faqSell[0]]]],
                ])],
        ];
    }

    /** TP3: the design's own banner photo (sites.templates.*.hero_photo), else a luxury exterior. */
    public static function heroPhoto(?string $template): string
    {
        $pick = $template !== null ? config("sites.templates.{$template}.hero_photo") : null;

        return is_array($pick) && isset($pick[0], $pick[1]) ? DemoPhotos::pick((string) $pick[0], (int) $pick[1]) : DemoPhotos::pick('exterior_luxury', 0);
    }

    /**
     * Three starter blog posts (Markdown), with demo cover photos.
     *
     * @param  array<string, mixed>  $info
     * @return list<array{slug: string, title: string, category: string, excerpt: string, body: string, cover: string}>
     */
    public static function posts(array $info): array
    {
        $area = trim((string) ($info['area'] ?? '')) ?: 'your area';

        return [
            ['slug' => 'first-time-buyer-guide', 'title' => "A first-time buyer's guide to {$area}", 'category' => 'Buying', 'cover' => DemoPhotos::pick('exterior_family', 5),
                'excerpt' => 'Six steps from "just looking" to the keys, in plain words.',
                'body' => "Buying your first home is exciting, and a little overwhelming. Here is how it works, step by step.\n\n"
                    ."## 1. Know your budget\nTalk to a lender before you start touring. A pre-approval tells you what you can spend and makes your offer stronger.\n\n"
                    ."## 2. Decide what matters\nWrite down your must-haves (bedrooms, commute, schools) and your nice-to-haves. It keeps the search focused.\n\n"
                    ."## 3. Search and save\nSave homes you like and turn on alerts, so you hear about new listings in {$area} the moment they come on the market.\n\n"
                    ."## 4. Tour with an agent\nAn agent spots what photos don't show and knows how fast homes are selling in each neighborhood.\n\n"
                    ."## 5. Make an offer\nWe look at recent nearby sales together and write an offer that is strong without overpaying.\n\n"
                    ."## 6. Inspect and close\nThe inspection, appraisal and loan approval happen next. We keep every deadline on track until you get the keys.\n\n"
                    .'Ready to start? [Search homes for sale](/homes) or [talk to an agent](/contact).'],
            ['slug' => 'before-you-list-checklist', 'title' => 'Ten things to do before you list your home', 'category' => 'Selling', 'cover' => DemoPhotos::pick('kitchen', 4),
                'excerpt' => 'Small jobs that make a big difference to buyers, and the ones to skip.',
                'body' => "Buyers decide in the first few minutes. These ten jobs help your home make a great first impression.\n\n"
                    ."1. Clear the clutter: counters, closets and the garage.\n2. Deep clean, including windows and grout.\n3. Paint scuffed walls a light, neutral color.\n"
                    ."4. Fix what buyers will notice: dripping taps, sticking doors, missing bulbs.\n5. Freshen the front door and entry.\n6. Mow, edge and add a few plants by the door.\n"
                    ."7. Let in light: open blinds and use bright, warm bulbs.\n8. Take personal photos down.\n9. Gather your utility bills and warranties.\n"
                    ."10. Find out what your home is worth before you pick a price.\n\n"
                    .'Skip big remodels right before selling; they rarely pay back. [Get your free home value](/home-value).'],
            ['slug' => 'how-to-read-the-market', 'title' => "How to read the {$area} housing market", 'category' => 'Market', 'cover' => DemoPhotos::pick('neighbourhood', 3),
                'excerpt' => 'Three numbers that tell you whether it is a good time to buy or sell.',
                'body' => "Headlines about the national market don't tell you much about your street. These three local numbers do.\n\n"
                    ."## Days on market\nHow long homes take to sell. Short times mean buyers compete; long times give buyers room to negotiate.\n\n"
                    ."## Price per square foot\nA fair way to compare homes of different sizes in the same neighborhood.\n\n"
                    ."## Price changes\nWhen many sellers cut their prices, buyers have the upper hand. When few do, sellers do.\n\n"
                    .'You can see these numbers for each neighborhood on our [Communities](/communities) page, updated every day.'],
        ];
    }

    /**
     * The cities (as market page slugs, "houston-tx") in the brokerage's state, for the Explore sections.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    public static function places(array $info): array
    {
        $state = self::state($info);
        $markets = $state !== null ? (SampleHomes::MARKETS[$state] ?? []) : array_merge(...array_values(SampleHomes::MARKETS));
        $out = [];
        foreach ($markets as $m) {
            $out[] = Str::slug($m[0]).'-'.strtolower($state ?? self::stateOfCity($m[0]));
        }

        return array_slice($out, 0, 8);
    }

    /** @param  array<string, mixed>  $info */
    private static function state(array $info): ?string
    {
        $st = strtoupper(trim((string) ($info['state'] ?? '')));
        if (isset(SampleHomes::MARKETS[$st])) {
            return $st;
        }
        $area = strtolower((string) ($info['area'] ?? ''));
        foreach (SampleHomes::MARKETS as $code => $cities) {
            foreach ($cities as $c) {
                if ($area !== '' && str_contains($area, strtolower($c[0]))) {
                    return $code;
                }
            }
        }

        return null;
    }

    private static function stateOfCity(string $city): string
    {
        foreach (SampleHomes::MARKETS as $code => $cities) {
            foreach ($cities as $c) {
                if ($c[0] === $city) {
                    return $code;
                }
            }
        }

        return 'TX';
    }

    /**
     * A skyline photo for the brokerage's city when there is one (Houston, Austin, Dallas, Miami …), else its state's
     * main city, else a neighborhood from above.
     *
     * @param  array<string, mixed>  $info
     */
    private static function cityPhoto(array $info): string
    {
        $area = strtolower((string) ($info['area'] ?? ''));
        $groups = SampleHomes::photoGroups();
        foreach ($groups as $group => $list) {
            if (str_starts_with((string) $group, 'city_') && $area !== '' && str_contains($area, str_replace('_', ' ', substr((string) $group, 5)))) {
                return DemoPhotos::pick((string) $group);
            }
        }
        $byState = ['TX' => 'city_houston', 'FL' => 'city_miami'];
        $state = self::state($info);

        return DemoPhotos::pick($state !== null && isset($byState[$state]) ? $byState[$state] : 'neighbourhood', 4);
    }
}
