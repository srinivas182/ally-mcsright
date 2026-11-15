<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Ledger\Books\BooksSync;
use Modules\Ledger\Books\QuickBooks;
use Modules\Ledger\Books\Xero;
use Modules\Ledger\Models\LedgerAccount;
use Throwable;

/**
 * Finance → Books (specs/connect/13): map RightAlly's accounts to the brokerage's QuickBooks or Xero accounts once,
 * pick from when journals post, and see what is waiting. Seeing it needs finance.ledger.view; changing it needs
 * finance.ledger.adjust (it decides what reaches the books).
 */
final class BooksController
{
    private const APPS = ['quickbooks' => ['QuickBooks Online', QuickBooks::class], 'xero' => ['Xero', Xero::class]];

    public function index(ConnectorKit $kit, BooksSync $sync): Response
    {
        Gate::authorize('finance.ledger.view');
        $accounts = LedgerAccount::query()->whereNull('parent_id')->where('status', 'active')->orderBy('code')->get()
            ->map(fn (LedgerAccount $a): array => ['code' => $a->code, 'name' => $a->name, 'type' => $a->type->value])->all();
        $books = [];
        foreach (self::APPS as $key => [$name, $driver]) {
            foreach ($kit->connections($key) as $c) {
                try {
                    $theirs = app($driver)->accounts($c);
                    $error = null;
                } catch (NeedsReconnect $e) {
                    [$theirs, $error] = [[], $e->getMessage()];
                } catch (Throwable) {
                    [$theirs, $error] = [[], $name.' didn\'t answer just now. Try again in a minute.'];
                }
                $books[] = ['ulid' => $c->ulid, 'app' => $key, 'name' => $name, 'post_from' => $sync->postFrom($c), 'map' => (object) (array) ($c->settings['map'] ?? []),
                    'waiting' => $c->settings['waiting'] ?? null, 'accounts' => $theirs, 'error' => $error, 'org' => $c->settings['xero_org'] ?? null];
            }
        }

        return Inertia::render('finance/books', ['ours' => $accounts, 'books' => $books, 'can' => ['manage' => Gate::allows('finance.ledger.adjust')]]);
    }

    public function save(Request $request, string $connection, ConnectorKit $kit): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $data = $request->validate(['map' => ['array'], 'map.*' => ['nullable', 'string', 'max:100'], 'post_from' => ['required', 'date']]);
        $c = self::find($kit, $connection);
        $codes = LedgerAccount::query()->whereNull('parent_id')->pluck('code')->all();
        $map = array_filter(array_intersect_key((array) ($data['map'] ?? []), array_flip($codes)), fn ($v) => is_string($v) && $v !== '');
        $kit->saveSettings($c, ['map' => $map, 'post_from' => substr((string) $data['post_from'], 0, 10)]);
        app(AuditLogger::class)->record('finance.books.mapped', metadata: ['app' => $c->appKey, 'mapped' => count($map), 'post_from' => $data['post_from']], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Journals post on the next run (every hour), or press "Sync now" on the app.']);

        return back();
    }

    private static function find(ConnectorKit $kit, string $ulid): ConnectionData
    {
        foreach (array_keys(self::APPS) as $key) {
            foreach ($kit->connections($key) as $c) {
                if ($c->ulid === $ulid) {
                    return $c;
                }
            }
        }
        abort(404);
    }
}
