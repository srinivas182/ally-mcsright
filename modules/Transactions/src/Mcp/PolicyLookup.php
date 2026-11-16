<?php

declare(strict_types=1);

namespace Modules\Transactions\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Transactions\Policies\PolicyAnswers;

#[Description('Answers a question about your brokerage\'s policies and state rules, only from its own documents, with the passage quoted. Says so when the documents do not answer it.')]
#[IsReadOnly]
final class PolicyLookup extends RightAllyTool
{
    protected string $name = 'policy_lookup';

    public function schema(JsonSchema $schema): array
    {
        return ['question' => $schema->string()->description('The policy question, in plain English or Spanish')->required()];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $q = (string) $request->validate(['question' => ['required', 'string', 'max:1000']])['question'];
        $a = app(PolicyAnswers::class)->answer($user, $q);

        return $a['found'] ? ['answer' => $a['answer'], 'sources' => $a['sources']]
            : ['answer' => null, 'note' => 'Your brokerage\'s documents do not answer this. Ask it in RightAlly (Policies) so your broker sees it.', 'link' => url('/policies')];
    }
}
