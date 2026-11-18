<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Listings\Jobs\RebuildListingSearch;
use Modules\Listings\Search\SearchIndex;

/** HQ → MLS → Fast search (SR1): the search server's health, Test-mode comparisons, and rebuilding the index. */
final class HqSearchController
{
    public function index(SearchIndex $index): Response
    {
        return Inertia::render('hq/mls-search', ['report' => $index->report()]);
    }

    public function rebuild(SearchIndex $index): RedirectResponse
    {
        if ($index->engine() === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Connect a search server in Outside services first (Test or Live).']);

            return back();
        }
        RebuildListingSearch::dispatch();
        app(AuditLogger::class)->record('listings.search.rebuild', severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rebuilding the search index in the background.']);

        return back();
    }
}
