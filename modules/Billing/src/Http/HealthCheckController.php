<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\HealthCheck\HealthChecks;
use Modules\Billing\HealthCheck\HealthScore;
use Modules\Billing\HealthCheck\PreviewSamples;
use Modules\Billing\HealthCheck\Tools;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Simple\SimpleMode;
use Modules\Core\Support\Connections;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The free Brokerage Health Check on the RightAlly host (specs/billing/06): the questionnaire (a search-friendly
 * page), the private report, the branded preview, and HQ's list of checks. Reports and previews are never indexed.
 */
final class HealthCheckController
{
    public function start(): HttpResponse
    {
        return response()->view('billing::health.start', ['tools' => Tools::all(), 'questions' => HealthScore::QUESTIONS, 'whoPays' => HealthScore::WHO_PAYS,
            'canonical' => 'https://'.config('core.hq_host').'/health-check']);
    }

    public function store(Request $request, HealthChecks $checks): RedirectResponse
    {
        // A field people never see: bots fill it in.
        abort_if(filled($request->input('fax')), 422);
        $data = $request->validate([
            'brokerage' => ['required', 'string', 'max:160'], 'contact_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'],
            'state' => ['nullable', 'string', 'size:2', 'alpha'], 'agents' => ['required', 'integer', 'min:1', 'max:20000'],
            'answers' => ['required', 'array'], ...collect(HealthScore::QUESTIONS)->mapWithKeys(fn (array $q, string $k): array => ["answers.{$k}" => ['required', Rule::in(array_keys($q['answers']))]])->all(),
            'who_pays' => ['required', Rule::in(array_keys(HealthScore::WHO_PAYS))],
            'tools' => ['nullable', 'array', 'max:15'], 'tools.*.key' => ['nullable', Rule::in(array_keys(Tools::all()))], 'other_name' => ['nullable', 'string', 'max:60'],
            'tools.*.cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'tools.*.per_agent' => ['nullable', 'boolean'],
            'website_url' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(\/\S*)?$/i'], 'website_ok' => ['nullable', 'boolean'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'contact_ok' => ['nullable', 'boolean'],
        ], ['website_url.regex' => 'Enter a web address such as yourbrokerage.com.']);
        if (filled($data['website_url'] ?? null) && ! ($data['website_ok'] ?? false)) {
            throw ValidationException::withMessages(['website_ok' => 'Tick the box to confirm you manage this website, or leave the address empty.']);
        }
        $tools = array_values(array_filter((array) ($data['tools'] ?? []), fn ($t): bool => is_array($t) && ($t['key'] ?? '') !== '' && (float) ($t['cost'] ?? 0) > 0));
        $made = $checks->create([
            'brokerage' => (string) $data['brokerage'], 'contact_name' => (string) $data['contact_name'], 'email' => (string) $data['email'], 'state' => $data['state'] ?? null,
            'agents' => (int) $data['agents'], 'answers' => array_map('strval', (array) $data['answers']), 'who_pays' => (string) $data['who_pays'],
            'tools' => array_map(fn (array $t): array => ['key' => (string) $t['key'], 'name' => $data['other_name'] ?? null, 'cost' => (string) $t['cost'], 'per_agent' => (bool) ($t['per_agent'] ?? false)], $tools),
            'website_url' => $data['website_url'] ?? null, 'brand_color' => $data['brand_color'] ?? null, 'contact_ok' => (bool) ($data['contact_ok'] ?? false),
        ], $request->file('logo'));

        return redirect('/health-check/r/'.$made['token']);
    }

    public function report(string $token): HttpResponse
    {
        $row = HealthChecks::byToken($token) ?? abort(404);
        DB::connection(Connections::control())->table('health_checks')->where('id', $row->id)->whereNull('viewed_at')->update(['viewed_at' => now()]);

        return self::private(response()->view('billing::health.report', ['r' => HealthChecks::view($row), 'token' => $token, 'hq' => false]));
    }

    public function preview(Request $request, string $token): HttpResponse
    {
        $row = HealthChecks::byToken($token) ?? abort(404);

        return self::renderPreview($row, $request->query('lang') === 'es' ? 'es' : 'en', '/health-check/r/'.$token);
    }

    public function logo(string $token): StreamedResponse
    {
        $row = HealthChecks::byToken($token) ?? abort(404);

        return self::logoOf($row);
    }

    public function destroy(string $token, HealthChecks $checks): RedirectResponse
    {
        $checks->delete(HealthChecks::byToken($token) ?? abort(404));

        return redirect('/health-check')->with('deleted', true);
    }

    /** HQ: every check, newest first, and whether the broker asked to be contacted. */
    public function hq(): Response
    {
        $rows = DB::connection(Connections::control())->table('health_checks')->orderByDesc('id')->limit(200)->get()->map(function (stdClass $r): array {
            $scores = json_decode((string) $r->scores, true);
            $savings = json_decode((string) $r->savings, true);
            $best = is_array($savings) && $savings['best'] !== null ? $savings['options'][$savings['best']] : null;

            return ['ulid' => (string) $r->ulid, 'at' => (string) $r->created_at, 'brokerage' => (string) $r->brokerage, 'name' => (string) $r->contact_name, 'email' => (string) $r->email,
                'state' => $r->state, 'agents' => (int) $r->agents, 'score' => (int) ($scores['overall'] ?? 0), 'website' => $r->website_url, 'saves_cents' => $best['saves_cents'] ?? null,
                'contact_ok' => (bool) $r->contact_ok, 'viewed' => $r->viewed_at !== null];
        })->all();

        return Inertia::render('hq/health-checks', ['checks' => $rows]);
    }

    public function hqShow(string $ulid): HttpResponse
    {
        $row = HealthChecks::find($ulid) ?? abort(404);
        app(AuditLogger::class)->record('billing.health_check.viewed', metadata: ['brokerage' => $row->brokerage], severity: 'info');

        return self::private(response()->view('billing::health.report', ['r' => HealthChecks::view($row), 'token' => null, 'hq' => true]));
    }

    public function hqPreview(Request $request, string $ulid): HttpResponse
    {
        return self::renderPreview(HealthChecks::find($ulid) ?? abort(404), $request->query('lang') === 'es' ? 'es' : 'en', '/hq/health-checks/'.$ulid);
    }

    public function hqLogo(string $ulid): StreamedResponse
    {
        return self::logoOf(HealthChecks::find($ulid) ?? abort(404));
    }

    private static function renderPreview(stdClass $row, string $lang, string $base): HttpResponse
    {
        $r = HealthChecks::view($row);
        [$red, $green, $blue] = sscanf($r['brand_color'], '#%02x%02x%02x') ?? [67, 56, 202];
        $light = (0.2126 * (int) $red + 0.7152 * (int) $green + 0.0722 * (int) $blue) / 255 > 0.6;

        return self::private(response()->view('billing::health.preview', ['r' => $r, 'base' => $base, 'lang' => $lang, 'w' => SimpleMode::words($lang),
            'on' => $light ? '#111827' : '#ffffff', 'samples' => PreviewSamples::for($lang)]));
    }

    private static function logoOf(stdClass $row): StreamedResponse
    {
        abort_if($row->logo_path === null || ! Storage::disk('local')->exists((string) $row->logo_path), 404);

        return Storage::disk('local')->response((string) $row->logo_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'"]);
    }

    private static function private(HttpResponse $response): HttpResponse
    {
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);

        return $response;
    }
}
