<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use stdClass;

/**
 * Brokerage Health Checks (specs/billing/06): stores a check, works out its scores and savings, runs the website
 * check, and emails the broker the private link to the report. Reports are opened by a random link whose hash is the
 * only copy kept; they expire after 180 days and the broker can delete theirs at any time.
 */
final class HealthChecks
{
    public const KEEP_DAYS = 180;

    public const DEFAULT_COLOR = '#4338ca';

    public function __construct(private readonly Savings $savings, private readonly WebsiteScan $scan) {}

    /**
     * @param  array{brokerage: string, contact_name: string, email: string, state?: string|null, agents: int, answers: array<string, string>, who_pays: string,
     *     tools: list<array{key: string, name?: string|null, cost: string|int|float, per_agent?: bool}>, website_url?: string|null, brand_color?: string|null, contact_ok?: bool}  $in
     * @return array{ulid: string, token: string}
     */
    public function create(array $in, ?UploadedFile $logo): array
    {
        $agents = max(1, (int) $in['agents']);
        $tools = [];
        foreach ($in['tools'] as $t) {
            $tool = Tools::find($t['key']);
            $cents = (int) round(((float) $t['cost']) * 100);
            if ($tool === null || $cents <= 0) {
                continue;
            }
            $other = trim((string) ($t['name'] ?? ''));
            $name = $t['key'] === 'other' && $other !== '' ? mb_substr($other, 0, 60) : $tool['name'];
            $tools[] = ['key' => $t['key'], 'name' => $name, 'monthly_cents' => ($t['per_agent'] ?? false) ? $cents * $agents : $cents, 'per_agent' => (bool) ($t['per_agent'] ?? false)];
        }
        $answers = array_intersect_key($in['answers'], HealthScore::QUESTIONS);
        $token = Str::random(40);
        $ulid = (string) Str::ulid();
        $logoPath = $logo?->storeAs('health-checks/'.$ulid, 'logo.'.$logo->extension(), 'local');
        $plain = array_map(fn (array $t): array => ['key' => $t['key'], 'name' => $t['name'], 'monthly_cents' => $t['monthly_cents']], $tools);

        DB::connection(Connections::control())->table('health_checks')->insert([
            'ulid' => $ulid, 'token_hash' => hash('sha256', $token), 'brokerage' => mb_substr(trim($in['brokerage']), 0, 160), 'contact_name' => mb_substr(trim($in['contact_name']), 0, 120),
            'email' => mb_strtolower(trim($in['email'])), 'state' => isset($in['state']) && $in['state'] !== '' ? strtoupper($in['state']) : null, 'agents' => $agents,
            'answers' => json_encode($answers + ['who_pays' => $in['who_pays']]), 'tools' => json_encode($tools),
            'website_url' => isset($in['website_url']) && trim($in['website_url']) !== '' ? WebsiteScan::normalise($in['website_url']) : null,
            // The form's colour picker starts on RightAlly's colour: left as it is, the website's own colour is used instead.
            'brand_color' => isset($in['brand_color']) && preg_match('/^#[0-9a-f]{6}$/i', $in['brand_color']) && mb_strtolower($in['brand_color']) !== self::DEFAULT_COLOR ? mb_strtolower($in['brand_color']) : null,
            'logo_path' => $logoPath ?: null, 'scores' => json_encode(HealthScore::score($answers, $plain, $agents, null)),
            'savings' => json_encode($this->savings->compare($plain, $agents, $in['who_pays'])), 'contact_ok' => (bool) ($in['contact_ok'] ?? false),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (isset($in['website_url']) && trim($in['website_url']) !== '') {
            dispatch(new ScanWebsite($ulid));
        }
        $row = self::find($ulid);
        if ($row !== null) {
            Notification::route('mail', (string) $row->email)->notify(new HealthReportMail((string) $row->brokerage, (string) $row->contact_name, self::reportUrl($token)));
        }

        return ['ulid' => $ulid, 'token' => $token];
    }

    /** Runs the website check and re-scores the report with it. */
    public function scanWebsite(string $ulid): void
    {
        $row = self::find($ulid);
        if ($row === null || $row->website_url === null) {
            return;
        }
        $result = $this->scan->run((string) $row->website_url);
        /** @var list<array{key: string, name: string, monthly_cents: int}> $tools */
        $tools = json_decode((string) $row->tools, true);
        /** @var array<string, string> $answers */
        $answers = json_decode((string) $row->answers, true);
        DB::connection(Connections::control())->table('health_checks')->where('id', $row->id)->update([
            'website' => json_encode($result),
            'brand_color' => $row->brand_color ?? $result['theme_color'],
            'scores' => json_encode(HealthScore::score($answers, $tools, (int) $row->agents, $result['status'] === 'done' ? ['score' => $result['score']] : null)),
            'updated_at' => now(),
        ]);
    }

    public static function byToken(string $token): ?stdClass
    {
        $row = DB::connection(Connections::control())->table('health_checks')->where('token_hash', hash('sha256', $token))->first();

        return $row !== null && now()->subDays(self::KEEP_DAYS)->lte($row->created_at) ? $row : null;
    }

    public static function find(string $ulid): ?stdClass
    {
        return DB::connection(Connections::control())->table('health_checks')->where('ulid', $ulid)->first();
    }

    public function delete(stdClass $row): void
    {
        if ($row->logo_path !== null) {
            Storage::disk('local')->deleteDirectory('health-checks/'.$row->ulid);
        }
        DB::connection(Connections::control())->table('health_checks')->where('id', $row->id)->delete();
    }

    /** Deletes reports older than 180 days, with their logos. */
    public function prune(): int
    {
        $old = DB::connection(Connections::control())->table('health_checks')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->get();
        foreach ($old as $row) {
            $this->delete($row);
        }

        return $old->count();
    }

    public static function reportUrl(string $token): string
    {
        return 'https://'.config('core.hq_host').'/health-check/r/'.$token;
    }

    /**
     * The report as the views need it.
     *
     * @return array<string, mixed>
     */
    public static function view(stdClass $row): array
    {
        return [
            'ulid' => (string) $row->ulid, 'brokerage' => (string) $row->brokerage, 'contact_name' => (string) $row->contact_name, 'agents' => (int) $row->agents,
            'created' => (string) $row->created_at, 'website_url' => $row->website_url, 'website' => $row->website !== null ? json_decode((string) $row->website, true) : null,
            'scores' => json_decode((string) $row->scores, true), 'savings' => json_decode((string) $row->savings, true), 'tools' => json_decode((string) $row->tools, true),
            'brand_color' => (string) ($row->brand_color ?? self::DEFAULT_COLOR), 'has_logo' => $row->logo_path !== null,
        ];
    }
}
