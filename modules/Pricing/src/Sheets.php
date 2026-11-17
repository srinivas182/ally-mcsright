<?php

declare(strict_types=1);

namespace Modules\Pricing;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Contact;
use Modules\Pricing\Models\Sheet;

/** Saved buyer cost estimates (CM1): on the contact, with a private share link. */
final class Sheets
{
    /**
     * Works the estimate out from the agent's inputs and the brokerage's defaults.
     *
     * @param  array<string, mixed>  $in  price, down_pct, loan, rate, years, tax_pct, insurance_pct, hoa, closing_pct, seller_credit, state
     * @return array{inputs: array<string, mixed>, result: array<string, mixed>}
     */
    public function work(array $in): array
    {
        $price = is_numeric($in['price'] ?? null) ? (int) round((float) $in['price'] * 100) : 0;
        if ($price < 1_000_000 || $price > 10_000_000_000) {
            throw ValidationException::withMessages(['price' => 'Enter a price between $10,000 and $100,000,000.']);
        }
        $d = app(Defaults::class)->values(isset($in['state']) && is_string($in['state']) && strlen($in['state']) === 2 ? $in['state'] : null);
        $num = fn (string $k, float $default): float => is_numeric($in[$k] ?? null) ? (float) $in[$k] : $default;
        $inputs = ['price_cents' => $price, 'down_pct' => $num('down_pct', 20), 'loan' => (string) ($in['loan'] ?? 'conventional'), 'rate' => $num('rate', $d['interest_rate']),
            'years' => (int) $num('years', (float) config('pricing.loan_years')), 'tax_pct' => $num('tax_pct', $d['property_tax_pct']), 'insurance_pct' => $num('insurance_pct', $d['insurance_pct']),
            'hoa_cents' => (int) round($num('hoa', 0) * 100), 'closing_pct' => $num('closing_pct', $d['buyer_closing_pct']), 'seller_credit_cents' => (int) round($num('seller_credit', 0) * 100)];

        return ['inputs' => $inputs, 'result' => Calculators::buyer($inputs)];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function save(User $by, array $in, ?Sheet $sheet = null): Sheet
    {
        ['inputs' => $inputs, 'result' => $result] = $this->work($in);
        $contact = isset($in['contact']) && is_string($in['contact']) && $in['contact'] !== '' ? Contact::query()->where('ulid', $in['contact'])->first() : null;
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160) ?: 'Buyer estimate at '.Cmas::usd($inputs['price_cents']);
        $sheet ??= new Sheet(['owner_user_id' => $by->id, 'kind' => 'buyer']);
        $sheet->fill(['contact_id' => $contact !== null ? $contact->id : $sheet->contact_id, 'title' => $title, 'inputs' => $inputs, 'result' => $result,
            'lang' => in_array($in['lang'] ?? 'en', ['en', 'es'], true) ? (string) ($in['lang'] ?? 'en') : 'en'])->save();
        if ($contact !== null && $sheet->wasRecentlyCreated) {
            app(Timeline::class)->system($contact, "Buyer cost estimate saved: {$title}.", $by->id);
        }

        return $sheet;
    }

    public function share(Sheet $sheet): string
    {
        $token = Str::random(40);
        $sheet->fill(['share_hash' => hash('sha256', $token), 'share_expires_at' => now()->addDays((int) config('pricing.share_days'))])->save();
        app(AuditLogger::class)->record('pricing.sheet.shared', $sheet);

        return app(SpeedToLead::class)->link('/estimate/'.$token);
    }

    public function byToken(string $token): ?Sheet
    {
        return strlen($token) === 40 ? Sheet::query()->where('share_hash', hash('sha256', $token))->where('share_expires_at', '>', now())->first() : null;
    }

    public function viewed(Sheet $sheet): void
    {
        $sheet->forceFill(['views' => $sheet->views + 1, 'last_viewed_at' => now()])->save();
    }
}
