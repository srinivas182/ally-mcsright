<?php

declare(strict_types=1);

namespace Modules\AI\Brand;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Upload the brand guide once (BV1, specs/core/08): the owner uploads the PDF; RightAlly reads its text (the file
 * itself is not kept) and the AI pulls out tone, words to use and avoid, emojis, sign-off, colours, fonts, taglines
 * and the required disclaimer. Nothing is used until the owner reviews and approves it.
 */
final class BrandGuide
{
    public const FEATURE = 'ai.brand_guide';

    public const MAX_MB = 20;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array<string, mixed> what the AI read, waiting for review */
    public function read(UploadedFile $file, User $by): array
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf' || $file->getSize() > self::MAX_MB * 1024 * 1024) {
            throw ValidationException::withMessages(['guide' => 'Upload the brand guide as a PDF up to '.self::MAX_MB.' MB.']);
        }
        $text = self::text((string) $file->getRealPath());
        if (mb_strlen(trim($text)) < 40) {
            throw ValidationException::withMessages(['guide' => 'There is no readable text in that PDF. If it is all pictures, type the rules in below instead.']);
        }
        try {
            $json = (array) app(AiGateway::class)->run(new AiTask(self::FEATURE, 'ai.brand_guide', 'brand-guide:'.$by->id.':'.now()->format('YmdHisv'),
                untrustedContent: [mb_substr($text, 0, 30_000)],
                outputSchema: ['type' => 'object', 'required' => ['tone'], 'properties' => ['tone' => ['type' => 'string']]], userId: $by->id))->json;
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['guide' => $e->getMessage()]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['guide' => 'The brand guide could not be read just now. Please try again in a minute.']);
        }
        $list = fn (string $k, int $max, int $len): array => array_values(array_slice(array_filter(array_map(fn (mixed $v): string => mb_substr(trim(strip_tags((string) $v)), 0, $len),
            is_array($json[$k] ?? null) ? $json[$k] : [])), 0, $max));
        $guide = [
            'tone' => in_array($json['tone'] ?? null, array_keys(BrandVoice::TONES), true) ? (string) $json['tone'] : 'warm',
            'use_words' => $list('use_words', 15, 40), 'avoid_words' => $list('avoid_words', 15, 40), 'emojis' => ($json['emojis'] ?? false) === true,
            'sign_off' => mb_substr(trim(strip_tags((string) ($json['sign_off'] ?? ''))), 0, 120),
            'colors' => array_values(array_filter($list('colors', 6, 7), fn (string $c): bool => preg_match('/^#[0-9A-Fa-f]{6}$/', $c) === 1)),
            'fonts' => $list('fonts', 3, 60), 'taglines' => $list('taglines', 3, 120), 'disclaimer' => mb_substr(trim(strip_tags((string) ($json['disclaimer'] ?? ''))), 0, 300),
        ];
        DB::connection(Connections::cell())->table('ai_brand_voice')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'guide' => json_encode($guide), 'guide_file' => mb_substr((string) $file->getClientOriginalName(), 0, 160), 'updated_at' => now(),
        ] + (DB::connection(Connections::cell())->table('ai_brand_voice')->where('tenant_id', $this->tenants->id())->exists() ? [] : ['use_words' => '[]', 'avoid_words' => '[]', 'created_at' => now()]));
        app(AuditLogger::class)->record('ai.brand_guide.read', metadata: ['file' => $file->getClientOriginalName()]);

        return $guide;
    }

    private static function text(string $path): string
    {
        try {
            $p = new Process(['pdftotext', '-layout', '-l', '40', $path, '-']);
            $p->setTimeout(30);
            $p->run();

            return $p->isSuccessful() ? $p->getOutput() : '';
        } catch (Throwable) {
            return '';
        }
    }
}
