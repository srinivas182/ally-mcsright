<?php

declare(strict_types=1);

namespace Modules\AI\Assistant;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\AI\Exceptions\UnknownFeature;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Mcp\McpToolbox;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Throwable;

/**
 * "Your AI Assistant" (specs/know/03): one back-office assistant, bottom-right on every page. Its name is fixed.
 *
 * Every capability is a skill: a RightAlly MCP tool, with the person's own permissions. So what people can do on the
 * screens, and with outside AI apps, they can ask for here; new modules and connectors bring their skills with them.
 * Each question runs a short loop (up to five steps): the AI picks a skill, answers, asks one question, offers to
 * connect an app, or hands over. Reading skills run at once; anything that changes data becomes a preview card that
 * runs only when the person presses "Do it".
 */
final class YourAiAssistant
{
    public const FEATURE = 'ai.assistant';

    public const NAME = 'Your AI Assistant';

    private const MAX_STEPS = 5;

    private const SCHEMA = ['type' => 'object', 'required' => ['step', 'text'], 'properties' => [
        'step' => ['type' => 'string'], 'text' => ['type' => 'string'], 'skill' => ['type' => 'string'], 'arguments' => ['type' => 'object'], 'app' => ['type' => 'string']]];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{path?: string, title?: string}  $page
     * @return array{conversation: string, turns: list<array<string, mixed>>}
     */
    public function ask(User $user, ?string $conversation, string $text, array $page = []): array
    {
        $text = mb_substr(trim(strip_tags($text)), 0, 2000);
        if ($text === '') {
            throw ValidationException::withMessages(['text' => 'Ask me something.']);
        }
        $conversation = is_string($conversation) && AssistantTurn::query()->where('user_id', $user->id)->where('conversation', $conversation)->exists()
            ? $conversation : (string) Str::ulid();
        $first = $this->turn($user, $conversation, 'person', $text);
        $skills = $this->skills($user);
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            try {
                $r = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'assistant.turn', 'assistant:'.$first->ulid.':'.$step,
                    inputs: ['name' => $user->first_name, 'role' => $user->kind->value, 'brokerage' => (string) $this->tenants->current()->name,
                        'today' => CarbonImmutable::now($user->timezone ?: 'America/Chicago')->toDayDateTimeString(),
                        'page' => mb_substr(trim(($page['title'] ?? '').' '.($page['path'] ?? '')), 0, 200) ?: 'unknown',
                        'skills' => (string) json_encode(array_values(array_map(fn (RightAllyTool $t): array => ['name' => $t->name(), 'does' => $t->description(),
                            'arguments' => $t->toArray()['inputSchema']['properties'] ?? [], 'changes_data' => $t->changesThings()], $skills)), JSON_UNESCAPED_SLASHES),
                        'connected' => $this->connected($user, $skills)],
                    untrustedContent: $this->history($user, $conversation), outputSchema: self::SCHEMA, userId: $user->id));
            } catch (UnknownFeature) {
                return $this->finish($user, $conversation, $first, 'Your AI Assistant isn\'t switched on for your brokerage yet. Ask your broker or RightAlly support.');
            } catch (InsufficientCredits $e) {
                return $this->finish($user, $conversation, $first, $e->getMessage());
            } catch (Throwable $e) {
                report($e);

                return $this->finish($user, $conversation, $first, 'I couldn\'t think that through just now. Please try again in a moment.');
            }
            $json = (array) $r->json;
            $kind = (string) ($json['step'] ?? 'answer');
            $say = trim((string) ($json['text'] ?? ''));
            if ($kind === 'skill' && isset($skills[(string) ($json['skill'] ?? '')])) {
                $tool = $skills[(string) $json['skill']];
                $args = is_array($json['arguments'] ?? null) ? $json['arguments'] : [];
                $result = $tool->forAssistant($user, $args, false);
                if (isset($result['preview'])) {
                    $this->turn($user, $conversation, 'card', $say !== '' ? $say : 'Here\'s what will happen. Nothing changes until you press Do it.',
                        ['type' => 'preview', 'skill' => $tool->name(), 'title' => $tool->title(), 'arguments' => $args, 'preview' => $result['preview']], 'waiting');

                    return $this->since($user, $conversation, $first);
                }
                $this->turn($user, $conversation, 'skill', null, ['skill' => $tool->name(), 'arguments' => $args,
                    'result' => mb_substr((string) json_encode($result['data'] ?? ['error' => $result['error'] ?? 'No result'], JSON_UNESCAPED_SLASHES), 0, 8000)]);

                continue;
            }
            if ($kind === 'connect') {
                $app = app(AppCatalog::class)->find(mb_strtolower(trim((string) ($json['app'] ?? ''))));
                if ($app !== null) {
                    $this->turn($user, $conversation, 'card', $say !== '' ? $say : $app->name.' isn\'t connected yet.',
                        ['type' => 'connect', 'app' => $app->key, 'name' => $app->name, 'page' => '/apps/'.$app->key, 'question' => $text], 'waiting');

                    return $this->since($user, $conversation, $first);
                }
            }

            return $this->finish($user, $conversation, $first, $say !== '' ? $say : 'Sorry, I\'m not sure how to help with that. The menu on the left has every page.');
        }

        return $this->finish($user, $conversation, $first, 'That took more steps than I can manage at once. Could you ask it in smaller parts?');
    }

    /**
     * The person pressed "Do it" on a preview: run the skill with their permissions, once.
     *
     * @return array{conversation: string, turns: list<array<string, mixed>>}
     */
    public function doIt(User $user, AssistantTurn $card): array
    {
        abort_unless($card->user_id === $user->id && $card->role === 'card', 404);
        $data = $card->data ?? [];
        if ($card->status !== 'waiting' || ($data['type'] ?? null) !== 'preview') {
            throw ValidationException::withMessages(['card' => 'This has already been done or cancelled.']);
        }
        $card->forceFill(['status' => 'done'])->save();
        $tool = $this->skills($user)[(string) ($data['skill'] ?? '')] ?? null;
        $result = $tool !== null ? $tool->forAssistant($user, (array) ($data['arguments'] ?? []), true) : ['error' => 'You no longer have access to this.'];
        app(AuditLogger::class)->record('assistant.done', metadata: ['skill' => $data['skill'] ?? null, 'ok' => ! isset($result['error'])], severity: 'notice');
        $text = isset($result['error']) ? 'That didn\'t go through: '.$result['error'] : 'Done. '.self::summary($result['data'] ?? null);

        return ['conversation' => $card->conversation, 'turns' => [self::view($card), self::view($this->turn($user, $card->conversation, 'assistant', trim($text)))]];
    }

    public function cancel(User $user, AssistantTurn $card): void
    {
        abort_unless($card->user_id === $user->id && $card->role === 'card', 404);
        if ($card->status === 'waiting') {
            $card->forceFill(['status' => 'cancelled'])->save();
            $this->turn($user, $card->conversation, 'assistant', 'OK, nothing was changed.');
        }
    }

    /** @return array{conversation: string|null, turns: list<array<string, mixed>>} the latest conversation (last 30 days) */
    public function latest(User $user): array
    {
        $last = AssistantTurn::query()->where('user_id', $user->id)->where('created_at', '>=', CarbonImmutable::now()->subDays(30))->latest('id')->first();
        if ($last === null) {
            return ['conversation' => null, 'turns' => []];
        }

        return ['conversation' => $last->conversation, 'turns' => array_values(AssistantTurn::query()->where('user_id', $user->id)->where('conversation', $last->conversation)
            ->whereIn('role', ['person', 'assistant', 'card'])->orderBy('id')->limit(60)->get()->map(fn (AssistantTurn $t): array => self::view($t))->values()->all())];
    }

    /** @return array<string, RightAllyTool> the skills this person may use, by name */
    public function skills(User $user): array
    {
        $out = [];
        foreach (app(McpToolbox::class)->all() as $class) {
            $tool = app($class);
            if ($tool->usableBy($user)) {
                $out[$tool->name()] = $tool;
            }
        }

        return $out;
    }

    /** @param  array<string, RightAllyTool>  $skills */
    private function connected(User $user, array $skills): string
    {
        if (! isset($skills['connected_apps'])) {
            return 'unknown';
        }
        $r = $skills['connected_apps']->forAssistant($user, [], true);
        $names = array_map(fn (array $c): string => $c['app'].' ('.$c['status'].')', (array) (((array) ($r['data'] ?? []))['connected'] ?? []));

        return $names !== [] ? implode(', ', $names) : 'none';
    }

    /** @return list<string> */
    private function history(User $user, string $conversation): array
    {
        return array_values(AssistantTurn::query()->where('user_id', $user->id)->where('conversation', $conversation)->latest('id')->limit(16)->get()->reverse()
            ->map(fn (AssistantTurn $t): string => match ($t->role) {
                'person' => 'Person: '.$t->body,
                'skill' => 'Result of skill '.($t->data['skill'] ?? '').' '.json_encode($t->data['arguments'] ?? []).': '.($t->data['result'] ?? ''),
                'card' => 'You showed a '.($t->data['type'] ?? '').' card ('.($t->status ?? '').'): '.$t->body,
                default => 'You: '.$t->body,
            })->all());
    }

    /** @return array{conversation: string, turns: list<array<string, mixed>>} */
    private function finish(User $user, string $conversation, AssistantTurn $first, string $text): array
    {
        if (class_exists(FairHousing::class) && FairHousing::screen($text, 'ai_content', 'removed', 'Your AI Assistant reply') !== []) {
            $text = 'I wrote something that needs rewording for Fair Housing, so I\'ve held it back. Could you ask in a different way?';
        }
        $this->turn($user, $conversation, 'assistant', $text);

        return $this->since($user, $conversation, $first);
    }

    /** @return array{conversation: string, turns: list<array<string, mixed>>} */
    private function since(User $user, string $conversation, AssistantTurn $first): array
    {
        return ['conversation' => $conversation, 'turns' => array_values(AssistantTurn::query()->where('user_id', $user->id)->where('conversation', $conversation)
            ->where('id', '>=', $first->id)->whereIn('role', ['person', 'assistant', 'card'])->orderBy('id')->get()->map(fn (AssistantTurn $t): array => self::view($t))->values()->all())];
    }

    /** @param  array<string, mixed>|null  $data */
    private function turn(User $user, string $conversation, string $role, ?string $body, ?array $data = null, ?string $status = null): AssistantTurn
    {
        return AssistantTurn::query()->create(['user_id' => $user->id, 'conversation' => $conversation, 'role' => $role, 'body' => $body, 'data' => $data, 'status' => $status]);
    }

    /** @return array<string, mixed> */
    private static function view(AssistantTurn $t): array
    {
        $d = $t->data ?? [];

        return ['ulid' => $t->ulid, 'role' => $t->role, 'text' => $t->body, 'status' => $t->status, 'at' => $t->created_at->toIso8601String(),
            'card' => $t->role === 'card' ? ['type' => $d['type'] ?? null, 'title' => $d['title'] ?? $d['name'] ?? null, 'preview' => $d['preview'] ?? null,
                'page' => $d['page'] ?? null, 'question' => $d['question'] ?? null] : null];
    }

    private static function summary(mixed $data): string
    {
        if (! is_array($data)) {
            return is_scalar($data) ? (string) $data : '';
        }
        $parts = [];
        foreach ($data as $k => $v) {
            if (is_scalar($v) && count($parts) < 4) {
                $parts[] = str_replace('_', ' ', (string) $k).': '.(is_bool($v) ? ($v ? 'yes' : 'no') : $v);
            }
        }

        return implode('. ', $parts).($parts !== [] ? '.' : '');
    }
}
