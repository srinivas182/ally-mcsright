<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agent and assistant landing page. The daily game plan replaces this placeholder (Know suite).
 */
final class AgentHomeController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('agent/home', [
            'firstName' => $user->first_name,
            'assisting' => $user->assistedAgent?->name,
        ]);
    }
}
