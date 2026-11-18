<?php

declare(strict_types=1);

namespace Modules\Core\Security\Http;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Security\Controls;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HQ → Security controls (TR1, specs/core/18): SOC 2 controls with live evidence, and the spreadsheet for auditors. */
#[CrossTenantAccess('SOC 2 evidence counts across brokerages')]
final class ControlsController
{
    public function index(Controls $controls): Response
    {
        return Inertia::render('hq/controls', ['controls' => $controls->all(), 'as_of' => now()->toIso8601String()]);
    }

    public function download(Controls $controls): StreamedResponse
    {
        $rows = $controls->all();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Control', 'Area', 'What RightAlly does', 'Evidence', 'Status', 'As of']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'], $r['area'], $r['control'], $r['evidence'], $r['status'], now()->toDateString()]);
            }
            fclose($out);
        }, 'rightally-soc2-evidence-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }
}
