<?php

declare(strict_types=1);

namespace Modules\Sites\Brief;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\Sites\Ailly\LiveChat;

/** Hot website chats nobody has taken over yet, in the daily brief (specs/core/09): a visitor talking to AIlly right now. */
final class ChatBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('chats', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $items = [];
        foreach (app(LiveChat::class)->live($user) as $chat) {
            if ($chat['taken_by'] !== null || ($chat['score'] ?? 0) < 60) {
                continue;
            }
            $items[] = ['key' => 'chat:'.$chat['ulid'], 'title' => 'A likely buyer or seller is chatting on your website now',
                'why' => 'Lead score '.$chat['score'].($chat['page'] !== '' ? ', on '.$chat['page'] : '').'. Take over while they\'re there.', 'action' => 'Take over',
                'url' => '/ailly/chats/'.$chat['ulid'], 'urgency' => 3, 'stakes_cents' => null];
        }

        return $items;
    }
}
