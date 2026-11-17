<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Crm\Actions\ManageContacts;

/**
 * Reads a forwarded lead email (specs/grow/04). Zillow, Realtor.com (and Opcity), Homes.com (and Homesnap) and Facebook lead ads are recognised by sender
 * and read with labelled-field parsing; anything else (or a portal email the parser can't fully read) goes
 * to AI. Whatever the reader, a name, email or phone number is only kept if it appears in the email itself.
 */
final class PortalEmail
{
    public const FEATURE = 'crm.lead_email';

    private const PORTALS = ['zillow.com' => 'zillow', 'trulia.com' => 'zillow', 'realtor.com' => 'realtor_com', 'move.com' => 'realtor_com', 'opcity.com' => 'realtor_com', 'homes.com' => 'homes_com',
        'homesnap.com' => 'homes_com', 'facebookmail.com' => 'facebook'];

    /** Addresses that belong to the portal or to us, never the lead's. */
    private const NOT_LEAD = '/(noreply|no-reply|donotreply|do-not-reply|notifications?|leads?|alerts?|support|info|premieragent|mailer)@|@(zillow|trulia|realtor|move|opcity|homes|homesnap|facebookmail|rightally)\./i';

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @param  array{from: string, reply_to?: string|null, subject: string, text: string}  $email
     * @return array{source: string, first_name: string, last_name: string, email: string|null, phone: string|null, message: string, intent: string|null, property: string|null, zip: string|null, price: int|null, read_by: string}
     */
    public function read(array $email): array
    {
        $text = self::plain($email['text']);
        $source = self::portal($email['from']) ?? 'other';
        $lead = self::labelled($text, $email['reply_to'] ?? null);
        $lead['source'] = $source;
        $lead['read_by'] = 'parser';

        $complete = ($lead['first_name'] !== '' || $lead['last_name'] !== '') && ($lead['email'] !== null || $lead['phone'] !== null);
        if (! $complete) {
            $ai = $this->ai($email['subject'], $text);
            if ($ai !== null) {
                foreach (['first_name', 'last_name', 'message', 'intent', 'property', 'zip'] as $key) {
                    if (($lead[$key] ?? '') === '' || $lead[$key] === null) {
                        $lead[$key] = $ai[$key];
                    }
                }
                $lead['email'] ??= $ai['email'];
                $lead['phone'] ??= $ai['phone'];
                $lead['price'] ??= $ai['price'];
                $lead['read_by'] = 'ai';
            }
        }

        // Only what the email says.
        $haystack = mb_strtolower($text.' '.($email['reply_to'] ?? '').' '.$email['subject']);
        $digits = preg_replace('/\D+/', '', $haystack) ?? '';
        if ($lead['email'] !== null && ! str_contains($haystack, mb_strtolower($lead['email']))) {
            $lead['email'] = null;
        }
        if ($lead['phone'] !== null && ! str_contains($digits, substr($lead['phone'], -10))) {
            $lead['phone'] = null;
        }
        foreach (['first_name', 'last_name'] as $key) {
            if ($lead[$key] !== '' && ! str_contains($haystack, mb_strtolower($lead[$key]))) {
                $lead[$key] = '';
            }
        }

        return $lead;
    }

    public static function portal(string $from): ?string
    {
        $from = mb_strtolower($from);
        foreach (self::PORTALS as $domain => $source) {
            if (preg_match('/[@.]'.preg_quote($domain, '/').'\b/', $from) === 1) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return array{first_name: string, last_name: string, email: string|null, phone: string|null, message: string, intent: string|null, property: string|null, zip: string|null, price: int|null}
     */
    public static function labelled(string $text, ?string $replyTo = null): array
    {
        $field = static function (array $labels) use ($text): string {
            foreach ($labels as $label) {
                if (preg_match('/^[ \t>*]*'.$label.'[ \t]*[:\-][ \t]*(.+)$/mi', $text, $m) === 1) {
                    return trim($m[1], " \t*");
                }
            }

            return '';
        };

        $name = $field(['(?:buyer |contact |lead |client )?name', 'from']);
        $first = $field(['first name']);
        $last = $field(['last name']);
        if ($first === '' && $last === '' && $name !== '' && ! str_contains($name, '@')) {
            $parts = preg_split('/\s+/', trim(preg_replace('/<[^>]*>/', '', $name) ?? '')) ?: [];
            $first = (string) array_shift($parts);
            $last = implode(' ', $parts);
        }

        $email = null;
        foreach (array_filter([$field(['e-?mail(?: address)?']), (string) $replyTo, $text]) as $candidate) {
            if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $candidate, $all) > 0) {
                foreach ($all[0] as $address) {
                    if (preg_match(self::NOT_LEAD, $address) !== 1) {
                        $email = mb_strtolower($address);
                        break 2;
                    }
                }
            }
        }

        $phone = null;
        foreach (array_filter([$field(['phone(?: number)?', 'mobile', 'cell', 'tel']), $text]) as $candidate) {
            if (preg_match('/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/', $candidate, $m) === 1) {
                $phone = ManageContacts::e164($m[0]);
                if ($phone !== null) {
                    break;
                }
            }
        }

        $message = $field(['message', 'comments?', 'question', 'note']);
        $property = $field(['property(?: address)?', 'listing(?: address)?', 'home', 'address']);
        $zip = preg_match('/\b(\d{5})(?:-\d{4})?\b/', $property, $z) === 1 ? $z[1] : null;
        $priceText = $field(['(?:list |listing )?price', 'budget']);
        $price = preg_match('/\$?\s*([\d,]{4,})/', $priceText, $p) === 1 ? (int) str_replace(',', '', $p[1]) : null;
        $lower = mb_strtolower($text);
        $intent = match (true) {
            str_contains($lower, 'home value') || str_contains($lower, 'selling') || str_contains($lower, 'sell my') => 'sell',
            str_contains($lower, 'rental') || str_contains($lower, 'for rent') => 'rent',
            str_contains($lower, 'tour') || str_contains($lower, 'interested in') || str_contains($lower, 'buy') || $property !== '' => 'buy',
            default => null,
        };

        return ['first_name' => mb_substr($first, 0, 80), 'last_name' => mb_substr($last, 0, 80), 'email' => $email, 'phone' => $phone,
            'message' => mb_substr($message, 0, 2000), 'intent' => $intent, 'property' => $property !== '' ? mb_substr($property, 0, 200) : null, 'zip' => $zip, 'price' => $price];
    }

    public static function plain(string $text): string
    {
        if (str_contains($text, '<')) {
            $text = (string) preg_replace(['/<(br|\/p|\/div|\/tr|\/li)[^>]*>/i', '/<\/t[dh]>/i'], ["\n", ': '], $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        }
        $text = (string) preg_replace('/:\s*:/', ':', $text);

        return mb_substr(trim((string) preg_replace("/[ \t]+/", ' ', $text)), 0, 20000);
    }

    /**
     * @return array{first_name: string, last_name: string, email: string|null, phone: string|null, message: string, intent: string|null, property: string|null, zip: string|null, price: int|null}|null
     */
    private function ai(string $subject, string $text): ?array
    {
        try {
            $result = $this->gateway->run(new AiTask(self::FEATURE, 'crm.lead_email', 'crm-lead-email:'.hash('sha256', $subject.$text),
                untrustedContent: ["Subject: {$subject}\n\n".mb_substr($text, 0, 8000)],
                outputSchema: ['type' => 'object', 'properties' => ['first_name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string']]]));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        $j = (array) $result->json;
        $s = static fn (string $k, int $max): string => mb_substr(trim((string) ($j[$k] ?? '')), 0, $max);
        $email = mb_strtolower($s('email', 254));
        $price = preg_replace('/\D+/', '', $s('price', 20)) ?? '';

        return ['first_name' => $s('first_name', 80), 'last_name' => $s('last_name', 80),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match(self::NOT_LEAD, $email) !== 1 ? $email : null,
            'phone' => ManageContacts::e164($s('phone', 30)), 'message' => $s('message', 2000),
            'intent' => in_array($j['intent'] ?? null, ['buy', 'sell', 'rent'], true) ? (string) $j['intent'] : null,
            'property' => $s('property', 200) !== '' ? $s('property', 200) : null, 'zip' => preg_match('/^\d{5}$/', $s('zip', 5)) === 1 ? $s('zip', 5) : null,
            'price' => $price !== '' && strlen($price) <= 9 ? (int) $price : null];
    }
}
