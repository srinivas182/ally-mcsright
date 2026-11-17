<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Forms\Library\FieldMapper;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PDF to smart form (specs/forms/04, FB1). A brokerage's own PDF usually has no fillable fields, so agents had
 * to draw every box by hand. This finds the fill-in blanks for them:
 *  - the words on each page are read with their positions (from the PDF's text, or by OCR for a scan);
 *  - a run of underscores is a blank, and "[ ]" or "☐" is a tick box; the words beside or under it are its label;
 *  - each blank becomes a field placed on that line, named from its label, and tied to deal data
 *    (price, address, buyer names…) and to a signer (Buyer 1, Seller 1…) by the same rules as imported forms;
 *  - optionally AI tidies the names and links only from the label words, never the page layout; anything it
 *    says that is not on our short list of links and signers is ignored.
 * Fields are suggestions: they are added beside any the agent already placed (never on top of one) and the agent
 * checks them in the editor before the form is used. Nothing is signed or sent from here.
 */
final class SmartForm
{
    public const FEATURE = 'forms.smart_fields';

    /** A blank needs at least this many underscores, so ordinary text with an underscore is left alone. */
    private const BLANK = '/_{4,}/';

    private const BOX = '/^(☐|□|\[\s?\]|\(\s?\))$/u';

    /** The links and signers the AI may choose from. */
    public const BINDS = ['deal.address', 'deal.city', 'deal.postal_code', 'deal.price', 'deal.closing_date', 'deal.contract_date', 'deal.mls',
        'buyer.names', 'buyer.1.name', 'buyer.2.name', 'seller.names', 'seller.1.name', 'seller.2.name', 'agent.name', 'agent.email', 'agent.phone', 'brokerage.name'];

    public const ROLES = ['buyer1', 'buyer2', 'seller1', 'seller2', 'listing_agent', 'buyer_agent'];

    private const AI_SCHEMA = ['type' => 'object', 'required' => ['fields'], 'properties' => ['fields' => ['type' => 'array', 'items' => ['type' => 'object',
        'required' => ['n', 'label'], 'properties' => ['n' => ['type' => 'integer'], 'label' => ['type' => 'string'], 'type' => ['type' => 'string'],
            'bind' => ['type' => 'string'], 'role' => ['type' => 'string'], 'required' => ['type' => 'boolean']]]]]];

    public function __construct(private readonly Templates $templates, private readonly AiGateway $gateway) {}

    /**
     * Finds the blanks in a template's PDF and adds them as fields for the agent to check.
     *
     * @return array{added: int, skipped: int, scanned: bool, ai: bool}
     */
    public function detect(string $ulid, User $by, bool $useAi = true): array
    {
        $t = $this->templates->find($ulid);
        if ($t === null) {
            throw ValidationException::withMessages(['template' => 'That form was not found.']);
        }
        $bytes = (string) Storage::disk('local')->get($t['path']);
        $words = self::words($bytes);
        $scanned = false;
        if ($words === []) {
            $words = self::ocrWords($bytes, $t['pages']);
            $scanned = true;
        }
        $suggested = self::fields(self::blanks($words), $t['page_sizes']);
        if ($suggested === []) {
            throw ValidationException::withMessages(['template' => $scanned
                ? 'This looks like a scan and I could not find fill-in lines on it. Please place the fields yourself.'
                : 'I could not find any fill-in lines ("_____") or tick boxes on this form. Please place the fields yourself.']);
        }
        $ai = false;
        if ($useAi) {
            try {
                $suggested = $this->tidy($suggested, $ulid, $by);
                $ai = true;
            } catch (Throwable) {
                // No credits or AI unavailable: the rules-based names and links still stand.
            }
        }

        [$merged, $added, $skipped] = self::merge($t['fields'], $suggested);
        $this->templates->saveFields($ulid, $merged, $by);
        app(AuditLogger::class)->record('forms.template.fields_found', metadata: ['template' => $ulid, 'added' => $added, 'skipped' => $skipped, 'scanned' => $scanned, 'ai' => $ai]);

        return ['added' => $added, 'skipped' => $skipped, 'scanned' => $scanned, 'ai' => $ai];
    }

    /**
     * Words with their boxes, from the PDF's own text layer (points, top-left origin).
     *
     * @return list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>
     */
    public static function words(string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sf');
        file_put_contents((string) $file, $pdf);
        try {
            $p = new Process(['pdftotext', '-bbox', '-l', '40', (string) $file, '-']);
            $p->setTimeout(60)->run();

            return $p->isSuccessful() ? self::parseBbox($p->getOutput()) : [];
        } finally {
            @unlink((string) $file);
        }
    }

    /**
     * pdftotext -bbox output → words.
     *
     * @return list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>
     */
    public static function parseBbox(string $html): array
    {
        $out = [];
        $pages = preg_split('/<page\b/i', $html) ?: [];
        array_shift($pages);
        foreach ($pages as $i => $chunk) {
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/s', $chunk, $m, PREG_SET_ORDER);
            foreach ($m as $w) {
                $text = html_entity_decode($w[5], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (trim($text) !== '') {
                    $out[] = ['page' => $i + 1, 'x1' => (float) $w[1], 'y1' => (float) $w[2], 'x2' => (float) $w[3], 'y2' => (float) $w[4], 'text' => $text];
                }
            }
        }

        return $out;
    }

    /**
     * Words from a scan, by OCR at 150 dpi, scaled back to points.
     *
     * @return list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>
     */
    private static function ocrWords(string $pdf, int $pages): array
    {
        $dir = sys_get_temp_dir().'/sf-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        $file = $dir.'/in.pdf';
        file_put_contents($file, $pdf);
        $out = [];
        try {
            for ($page = 1; $page <= min($pages, 20); $page++) {
                $p = new Process(['pdftoppm', '-r', '150', '-gray', '-png', '-f', (string) $page, '-l', (string) $page, '-singlefile', $file, $dir.'/p']);
                $p->setTimeout(60)->run();
                if (! $p->isSuccessful() || ! is_file($dir.'/p.png')) {
                    continue;
                }
                $t = new Process(['tesseract', $dir.'/p.png', 'stdout', '-l', 'eng', '--psm', '3', 'tsv']);
                $t->setTimeout(120)->run();
                foreach (self::parseTsv($t->getOutput(), $page, 72 / 150) as $w) {
                    $out[] = $w;
                }
                @unlink($dir.'/p.png');
            }
        } finally {
            @unlink($file);
            @rmdir($dir);
        }

        return $out;
    }

    /**
     * Tesseract TSV → words (level 5 rows), scaled to points.
     *
     * @return list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>
     */
    public static function parseTsv(string $tsv, int $page, float $scale): array
    {
        $out = [];
        foreach (preg_split('/\R/', $tsv) ?: [] as $line) {
            $c = explode("\t", $line);
            if (count($c) < 12 || $c[0] !== '5' || trim($c[11]) === '') {
                continue;
            }
            [$l, $t, $w, $h] = [(float) $c[6], (float) $c[7], (float) $c[8], (float) $c[9]];
            $out[] = ['page' => $page, 'x1' => $l * $scale, 'y1' => $t * $scale, 'x2' => ($l + $w) * $scale, 'y2' => ($t + $h) * $scale, 'text' => trim($c[11])];
        }

        return $out;
    }

    /**
     * Blanks and tick boxes with the words that name them.
     *
     * @param  list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>  $words
     * @return list<array{page: int, x1: float, y1: float, x2: float, y2: float, kind: string, label: string}>
     */
    public static function blanks(array $words): array
    {
        $found = [];
        foreach ($words as $i => $w) {
            $box = preg_match(self::BOX, $w['text']) === 1;
            if (! $box && preg_match(self::BLANK, $w['text']) !== 1) {
                continue;
            }
            [$x1, $x2] = [$w['x1'], $w['x2']];
            if (! $box && preg_match('/^(.*?)(_{4,})(.*)$/u', $w['text'], $m) === 1) {
                // "Price:$______" — keep only the underscores' part of the word's width.
                $per = ($w['x2'] - $w['x1']) / mb_strlen($w['text']);
                $x1 = $w['x1'] + $per * mb_strlen($m[1]);
                $x2 = $x1 + $per * mb_strlen($m[2]);
            }
            $label = self::labelFor($words, $i, $x1, $x2);
            $found[] = ['page' => $w['page'], 'x1' => $x1, 'y1' => $w['y1'], 'x2' => $x2, 'y2' => $w['y2'], 'kind' => $box ? 'box' : 'line', 'label' => $label];
        }

        return $found;
    }

    /**
     * The words just before a blank on its line, else the words just under it ("Buyer's signature" under the line),
     * else just after it (a tick box's words come after the box).
     *
     * @param  list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>  $words
     */
    private static function labelFor(array $words, int $at, float $x1, float $x2): string
    {
        $me = $words[$at];
        $mid = ($me['y1'] + $me['y2']) / 2;
        $sameLine = array_filter($words, fn ($w, $j): bool => $j !== $at && $w['page'] === $me['page'] && abs(($w['y1'] + $w['y2']) / 2 - $mid) < 4
            && preg_match(self::BLANK, $w['text']) !== 1, ARRAY_FILTER_USE_BOTH);
        // Words before the blank on its line, walking left while they sit close together (stops at a gap or another blank).
        $before = array_values(array_filter($sameLine, fn ($w): bool => $w['x2'] <= $x1 + 1));
        usort($before, fn ($a, $b): int => $b['x1'] <=> $a['x1']);
        $before = array_reverse(self::chain($before, $x1, true));
        $prefix = '';
        if (preg_match('/^(.*?)_{4,}/u', $me['text'], $m) === 1) {
            $prefix = $m[1];
        }
        $text = trim(implode(' ', array_column($before, 'text')).' '.$prefix);
        if (preg_match(self::BOX, $me['text']) === 1) {
            $after = array_values(array_filter($sameLine, fn ($w): bool => $w['x1'] >= $x2 - 1));
            usort($after, fn ($a, $b): int => $a['x1'] <=> $b['x1']);
            $text = implode(' ', array_column(self::chain($after, $x2, false), 'text'));
        }
        if (self::clean($text) === '') {
            $under = array_values(array_filter($words, fn ($w): bool => $w['page'] === $me['page'] && $w['y1'] >= $me['y2'] - 1 && $w['y1'] - $me['y2'] < 14
                && $w['x2'] > $x1 && $w['x1'] < $x2 && preg_match(self::BLANK, $w['text']) !== 1));
            usort($under, fn ($a, $b): int => $a['x1'] <=> $b['x1']);
            $text = implode(' ', array_column(array_slice($under, 0, 6), 'text'));
        }

        return self::clean($text);
    }

    /**
     * Words in reading order away from the blank, kept while each is within a short gap of the last, at most 6.
     *
     * @param  list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>  $words  sorted away from the blank
     * @return list<array{page: int, x1: float, y1: float, x2: float, y2: float, text: string}>
     */
    private static function chain(array $words, float $edge, bool $leftwards): array
    {
        $out = [];
        foreach ($words as $w) {
            $gap = $leftwards ? $edge - $w['x2'] : $w['x1'] - $edge;
            if ($gap > 24 || count($out) >= 6) {
                break;
            }
            $out[] = $w;
            $edge = $leftwards ? $w['x1'] : $w['x2'];
        }

        return $out;
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[_:$#()\[\]☐□]+/u', ' ', $text)));

        return mb_substr($text, 0, 60);
    }

    /**
     * Blanks → template fields, using the import rules for names, types, links and signers.
     *
     * @param  list<array{page: int, x1: float, y1: float, x2: float, y2: float, kind: string, label: string}>  $blanks
     * @param  list<array{0: float, 1: float}>  $sizes
     * @return list<array<string, mixed>>
     */
    public static function fields(array $blanks, array $sizes): array
    {
        $raw = [];
        foreach ($blanks as $n => $b) {
            $size = $sizes[$b['page'] - 1] ?? null;
            if ($size === null) {
                continue;
            }
            $h = $b['kind'] === 'box' ? max(8.0, $b['y2'] - $b['y1']) : 14.0;
            $top = $b['kind'] === 'box' ? $b['y1'] : max(0.0, $b['y2'] - $h);
            $w = $b['kind'] === 'box' ? max(8.0, $b['x2'] - $b['x1']) : max(20.0, $b['x2'] - $b['x1']);
            $label = $b['label'] !== '' ? $b['label'] : 'Field '.($n + 1);
            // FieldMapper takes PDF rectangles (bottom-left origin).
            $raw[] = ['name' => $label, 'type' => $b['kind'] === 'box' ? 'checkbox' : 'text', 'page' => $b['page'],
                'rect' => [$b['x1'], (float) $size[1] - ($top + $h), $b['x1'] + $w, (float) $size[1] - $top]];
        }

        return array_map(fn (array $f): array => $f + ['suggested' => true], FieldMapper::map($raw, $sizes));
    }

    /**
     * Suggested fields that do not sit on a field already placed, added after the existing ones.
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $suggested
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public static function merge(array $existing, array $suggested): array
    {
        $out = $existing;
        $keys = array_flip(array_map(fn ($f): string => (string) $f['key'], $out));
        $added = 0;
        $skipped = 0;
        foreach ($suggested as $s) {
            foreach ($out as $e) {
                if ((int) $e['page'] === (int) $s['page'] && $s['x'] < $e['x'] + $e['w'] && $e['x'] < $s['x'] + $s['w'] && $s['y'] < $e['y'] + $e['h'] && $e['y'] < $s['y'] + $s['h']) {
                    $skipped++;

                    continue 2;
                }
            }
            $base = (string) $s['key'];
            $key = $base;
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = substr($base, 0, 36).'_'.$n;
            }
            $keys[$key] = true;
            if (in_array($s['type'], Templates::SIGNER_FIELDS, true) && in_array($s['role'] ?? null, [null, FieldMapper::UNASSIGNED], true)) {
                $s['role'] = FieldMapper::UNASSIGNED;
            }
            $out[] = ['key' => $key] + $s;
            $added++;
        }

        return [$out, $added, $skipped];
    }

    /**
     * AI tidies labels and picks links and signers from the label words only; its answers are checked against
     * our short lists, and a field keeps its rules-based values wherever the AI's answer is not allowed.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function tidy(array $fields, string $ulid, User $by): array
    {
        $list = [];
        foreach (array_slice($fields, 0, 120) as $n => $f) {
            $list[] = $n.': '.$f['label'].' ('.$f['type'].', page '.$f['page'].')';
        }
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'forms.smart_fields', 'smart-fields:'.$ulid.':'.md5(implode("\n", $list)),
            inputs: ['binds' => implode(', ', self::BINDS), 'roles' => implode(', ', self::ROLES)], untrustedContent: [implode("\n", $list)],
            outputSchema: self::AI_SCHEMA, userId: $by->id));
        foreach ((array) ($result->json['fields'] ?? []) as $a) {
            $n = (int) ($a['n'] ?? -1);
            if (! isset($fields[$n])) {
                continue;
            }
            $label = trim((string) ($a['label'] ?? ''));
            if ($label !== '') {
                $fields[$n]['label'] = mb_substr($label, 0, 80);
            }
            $type = (string) ($a['type'] ?? '');
            if (in_array($type, Templates::TYPES, true) && ($fields[$n]['type'] !== 'checkbox' || $type === 'checkbox')) {
                $fields[$n]['type'] = $type;
            }
            $signer = in_array($fields[$n]['type'], Templates::SIGNER_FIELDS, true);
            $bind = (string) ($a['bind'] ?? '');
            $fields[$n]['bind'] = $signer || $fields[$n]['type'] === 'checkbox' ? null : (in_array($bind, self::BINDS, true) ? $bind : $fields[$n]['bind']);
            $role = (string) ($a['role'] ?? '');
            $fields[$n]['role'] = $signer ? (in_array($role, self::ROLES, true) ? $role : ($fields[$n]['role'] ?? FieldMapper::UNASSIGNED)) : null;
            $fields[$n]['required'] = (bool) ($a['required'] ?? $fields[$n]['required']);
        }

        return $fields;
    }
}
