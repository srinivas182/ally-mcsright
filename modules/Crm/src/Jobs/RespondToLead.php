<?php

declare(strict_types=1);

namespace Modules\Crm\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Crm\Leads\AiResponder;

/** The AI first responder's next message for one lead (specs/grow/06). */
final class RespondToLead implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $leadId) {}

    public function handle(AiResponder $responder): void
    {
        $responder->respond($this->leadId);
    }
}
