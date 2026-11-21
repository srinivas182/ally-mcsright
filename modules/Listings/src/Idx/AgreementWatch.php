<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Watchtower\Settings as Watchtower;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use stdClass;
use Throwable;

/**
 * IDX11: MLS boards change their IDX agreement without telling anyone, and many keep it behind a member login. HQ saves
 * the address where each board publishes its form; every week RightAlly downloads it and compares it with the version
 * in use. A different file is kept aside and HQ is told ("Use the new version" or "Keep the current one"). Packets
 * already signed keep the version they were signed on. A form that cannot be downloaded (a login page, say) gets a
 * "confirm it is still current" reminder every 90 days instead. Nothing changes without HQ.
 */
final class AgreementWatch
{
    public const CONFIRM_EVERY_DAYS = 90;

    public function __construct(private readonly Http $http, private readonly AuditLogger $audit) {}

    /** True when HQ should confirm this board's form is still current (no watchable address, or not confirmed lately). */
    public static function due(stdClass $board): bool
    {
        if (($board->agreement_path ?? null) === null || ($board->visibility ?? '') !== 'public') {
            return false;
        }
        $confirmed = $board->agreement_confirmed_at ?? null;

        return $confirmed === null || strtotime((string) $confirmed) < now()->subDays(self::CONFIRM_EVERY_DAYS)->getTimestamp();
    }

    /**
     * Weekly: download every watched form, keep a newer version aside, and tell HQ what needs a look.
     *
     * @return array{checked: int, changed: int, due: int}
     */
    public function run(): array
    {
        $changed = [];
        $checked = 0;
        foreach ($this->db()->table('idx_boards')->whereNotNull('agreement_url')->get() as $board) {
            $checked++;
            if ($this->check($board)) {
                $changed[] = $board->name.' published a new version of its IDX agreement.';
            }
        }
        $due = array_values(array_map(fn (stdClass $b): string => $b->name.': please confirm its IDX agreement is still the current one.',
            array_filter($this->db()->table('idx_boards')->get()->all(), fn (stdClass $b): bool => self::due($b) && ($b->agreement_pending_path ?? null) === null)));
        $to = Watchtower::all()['recipients'];
        if (($changed !== [] || $due !== []) && $to !== []) {
            Mail::to($to)->queue(new IdxUpdateMail('MLS agreement forms: '.(count($changed) + count($due)).' to look at', [...$changed, ...$due,
                'Open HQ › IDX coverage › MLS boards. Each board shows "New version found" or "Confirm current".']));
        }

        return ['checked' => $checked, 'changed' => count($changed), 'due' => count($due)];
    }

    /** Downloads one board's form; true when a different version was found (and kept aside for HQ). */
    public function check(stdClass $board): bool
    {
        $note = null;
        $found = false;
        try {
            $response = $this->http->timeout(20)->withHeaders(['User-Agent' => 'RightAlly form watch'])->get((string) $board->agreement_url);
            $bytes = (string) $response->body();
            if (! $response->successful()) {
                $note = 'The address answered '.$response->status().'. Check it, or confirm the form by hand.';
            } elseif (! str_starts_with($bytes, '%PDF-')) {
                $note = 'The address shows a web page, not a PDF (often a member sign-in). Confirm the form by hand every '.self::CONFIRM_EVERY_DAYS.' days.';
            } else {
                $hash = hash('sha256', $bytes);
                if ($board->agreement_hash === null && $board->agreement_path === null) {
                    // The first download becomes the board's form.
                    $path = 'idx/agreement-templates/'.$board->code.'-'.now()->format('YmdHis').'.pdf';
                    Storage::disk('local')->put($path, $bytes);
                    $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_path' => $path, 'agreement_hash' => $hash]);
                } elseif ($board->agreement_hash === null) {
                    $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_hash' => $hash]);
                } elseif ($hash !== $board->agreement_hash && $hash !== hash('sha256', (string) ($board->agreement_pending_path !== null
                    ? Storage::disk('local')->get((string) $board->agreement_pending_path) : ''))) {
                    $path = 'idx/agreement-templates/pending/'.$board->code.'-'.now()->format('YmdHis').'.pdf';
                    Storage::disk('local')->put($path, $bytes);
                    $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_pending_path' => $path, 'agreement_pending_at' => now()]);
                    $this->audit->record('idx.board.form_changed', metadata: ['board' => $board->code], severity: 'notice');
                    $found = true;
                }
            }
        } catch (Throwable $e) {
            $note = 'Could not reach the address: '.mb_substr($e->getMessage(), 0, 150);
        }
        $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_watched_at' => now(), 'agreement_watch_note' => $note]);

        return $found;
    }

    /** HQ: use the newer version, keep the current one, confirm the current one, or check now. Returns what happened. */
    public function act(string $ulid, string $action, User $by): string
    {
        $board = $this->db()->table('idx_boards')->where('ulid', $ulid)->first();
        if (! $board instanceof stdClass) {
            throw ValidationException::withMessages(['board' => 'That board no longer exists.']);
        }
        switch ($action) {
            case 'use_new':
                if ($board->agreement_pending_path === null || ! Storage::disk('local')->exists((string) $board->agreement_pending_path)) {
                    throw ValidationException::withMessages(['board' => 'There is no new version to use.']);
                }
                app(Coverage::class)->saveAgreementPdf($ulid, (string) Storage::disk('local')->get((string) $board->agreement_pending_path), $by);

                return 'Now using version '.((int) $board->agreement_version + 1).' of the '.$board->code.' agreement. Agreements already signed keep their version.';
            case 'keep':
                $hash = $board->agreement_pending_path !== null ? hash('sha256', (string) Storage::disk('local')->get((string) $board->agreement_pending_path)) : $board->agreement_hash;
                $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_hash' => $hash, 'agreement_pending_path' => null, 'agreement_pending_at' => null,
                    'agreement_confirmed_at' => now()]);
                $this->audit->record('idx.board.form_kept', metadata: ['board' => $board->code, 'by' => $by->id]);

                return 'Kept the current version. The watch will only tell you if the form changes again.';
            case 'confirm':
                $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_confirmed_at' => now()]);
                $this->audit->record('idx.board.form_confirmed', metadata: ['board' => $board->code, 'by' => $by->id]);

                return 'Confirmed as current. We will ask again in '.self::CONFIRM_EVERY_DAYS.' days.';
            default:
                if ($board->agreement_url === null) {
                    throw ValidationException::withMessages(['board' => 'Save the address where the board publishes its form first.']);
                }

                return $this->check($board) ? 'A new version was found. Choose "Use the new version" or "Keep current".' : 'Checked: no new version.';
        }
    }

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }
}
