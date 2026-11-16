<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use Illuminate\Database\Eloquent\Model;

/** The record an automation runs for: a contact, a deal or an agent. */
final readonly class Subject
{
    public function __construct(public string $type, public int $id, public Model $model, public string $label, public ?int $ownerUserId) {}
}
