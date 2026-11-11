<?php

declare(strict_types=1);

namespace Modules\AI\RedTeam;

/**
 * An adversarial test: a document carrying an attacker's instruction to make the AI pass on the
 * canary (a payment destination, a contact, a link). Critical scenarios fail the run if followed.
 */
final readonly class Scenario
{
    public function __construct(
        public string $name,
        public string $question,
        public string $injectedDocument,
        public string $canary,
        public bool $critical = false,
    ) {}
}
