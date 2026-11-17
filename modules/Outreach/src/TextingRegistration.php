<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent;

/**
 * Guided A2P 10DLC registration (MK1, specs/crm/20). US carriers block business texts from numbers whose brand and
 * campaign are not registered. The brokerage answers plain questions; RightAlly fills in the use case, sample messages
 * and opt-in wording that match how RightAlly sends texts (consent only, STOP honoured, 8am–9pm), and HQ submits it
 * to the carrier registry through the platform's Twilio account and records the outcome. The EIN is encrypted.
 */
final class TextingRegistration
{
    public const STATUSES = ['draft' => 'Not sent yet', 'submitted' => 'With the carriers (usually 1–3 weeks)', 'approved' => 'Approved', 'rejected' => 'Needs changes'];

    public const BUSINESS_TYPES = ['llc' => 'LLC', 'corporation' => 'Corporation', 'partnership' => 'Partnership', 'sole_proprietor' => 'Sole proprietor', 'non_profit' => 'Non-profit'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{status: string, status_label: string, note: string|null, brand: array<string, string>, campaign: array<string, string>, submitted_at: string|null}
     */
    public function current(?int $tenantId = null): array
    {
        $tenantId ??= $this->tenants->id();
        $row = DB::connection(Connections::control())->table('outreach_texting_registrations')->where('tenant_id', $tenantId)->first();
        $brand = $row !== null ? (array) json_decode(Crypt::decryptString((string) $row->brand), true) : [];
        $status = (string) ($row->status ?? 'draft');

        return ['status' => $status, 'status_label' => self::STATUSES[$status] ?? $status, 'note' => $row?->note !== null ? (string) $row->note : null,
            'brand' => array_map('strval', $brand), 'campaign' => $row !== null ? array_map('strval', (array) json_decode((string) $row->campaign, true)) : $this->suggestedCampaign(),
            'submitted_at' => $row?->submitted_at !== null ? (string) $row->submitted_at : null];
    }

    /**
     * What RightAlly actually sends, written the way carriers want to read it.
     *
     * @return array<string, string>
     */
    public function suggestedCampaign(): array
    {
        $name = $this->tenants->current()->name;

        return [
            'use_case' => 'MIXED',
            'description' => "{$name} is a real estate brokerage. Our agents text people who asked to hear from us: replies to their enquiries, showing and appointment reminders, new listings that match their saved searches, and updates on their purchase or sale.",
            'sample_1' => "Hi Jane, this is Pat with {$name}. Thanks for asking about 12 Oak St. Would you like to see it this weekend? Reply STOP to opt out.",
            'sample_2' => "{$name}: Reminder, your showing at 44 Pecan Dr is tomorrow at 10:00 AM. Reply STOP to opt out.",
            'opt_in' => 'People opt in on our website forms by ticking an unticked box next to this wording: "'.Consent::DEFAULT_SMS_WORDING.'" or by texting us first. We record the wording, time and page of every opt-in.',
            'volume' => 'under_2000',
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function save(array $in, bool $submit, User $by): void
    {
        $brand = ['legal_name' => trim((string) ($in['legal_name'] ?? '')), 'business_type' => (string) ($in['business_type'] ?? ''), 'ein' => preg_replace('/\D/', '', (string) ($in['ein'] ?? '')) ?? '',
            'website' => trim((string) ($in['website'] ?? '')), 'street' => trim((string) ($in['street'] ?? '')), 'city' => trim((string) ($in['city'] ?? '')),
            'state' => strtoupper(trim((string) ($in['state'] ?? ''))), 'zip' => trim((string) ($in['zip'] ?? '')), 'contact_name' => trim((string) ($in['contact_name'] ?? '')),
            'contact_email' => trim((string) ($in['contact_email'] ?? '')), 'contact_phone' => trim((string) ($in['contact_phone'] ?? ''))];
        $campaign = array_map(fn ($v): string => mb_substr(trim((string) $v), 0, 1000), array_intersect_key($in, $this->suggestedCampaign())) + $this->suggestedCampaign();
        $current = $this->current();
        if ($brand['ein'] === '' && ($current['brand']['ein'] ?? '') !== '') {
            $brand['ein'] = $current['brand']['ein']; // left blank: keep the one on file (never shown again)
        }
        if ($submit) {
            $errors = [];
            foreach (['legal_name' => 'Your legal business name, exactly as on your IRS letter.', 'street' => 'The business address.', 'city' => 'The city.', 'zip' => 'The ZIP code.',
                'contact_name' => 'Who carriers can contact.', 'contact_email' => 'Their email.'] as $k => $message) {
                if ($brand[$k] === '') {
                    $errors[$k] = $message;
                }
            }
            if (! isset(self::BUSINESS_TYPES[$brand['business_type']])) {
                $errors['business_type'] = 'Choose the kind of business.';
            }
            if ($brand['business_type'] !== 'sole_proprietor' && preg_match('/^\d{9}$/', $brand['ein']) !== 1) {
                $errors['ein'] = 'The 9-digit EIN (tax ID) from your IRS letter.';
            }
            if (preg_match('/^[A-Z]{2}$/', $brand['state']) !== 1) {
                $errors['state'] = 'The two-letter state.';
            }
            if (filter_var($brand['website'], FILTER_VALIDATE_URL) === false) {
                $errors['website'] = 'Your website address, starting with https://. Carriers check it shows your business name.';
            }
            if (! str_contains(mb_strtolower($campaign['sample_1'].$campaign['sample_2']), 'stop')) {
                $errors['sample_1'] = 'Sample messages must say how to opt out (Reply STOP to opt out).';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        if (in_array($current['status'], ['submitted', 'approved'], true) && $submit) {
            throw ValidationException::withMessages(['legal_name' => 'This registration is already with the carriers.']);
        }
        DB::connection(Connections::control())->table('outreach_texting_registrations')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'status' => $submit ? 'submitted' : ($current['status'] === 'rejected' ? 'rejected' : 'draft'), 'brand' => Crypt::encryptString((string) json_encode($brand)),
            'campaign' => (string) json_encode($campaign), 'submitted_at' => $submit ? now() : null, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record($submit ? 'outreach.texting_registration_submitted' : 'outreach.texting_registration_saved', severity: $submit ? 'notice' : 'info');
    }

    /** HQ records what the carrier registry decided. */
    public function decide(int $tenantId, string $status, ?string $note, User $by): void
    {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Approved or needs changes.']);
        }
        DB::connection(Connections::control())->table('outreach_texting_registrations')->where('tenant_id', $tenantId)
            ->update(['status' => $status, 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'decided_at' => now(), 'updated_by' => $by->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('hq.texting_registration_decided', metadata: ['tenant' => $tenantId, 'status' => $status], severity: 'notice', tenantId: $tenantId);
    }
}
