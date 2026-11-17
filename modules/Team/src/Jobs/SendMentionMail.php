<?php

declare(strict_types=1);

namespace Modules\Team\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Team\Chat;
use Modules\Team\MentionMail;
use Modules\Team\Models\Channel;
use Modules\Team\Models\Message;

/** Ten minutes after an @mention: emails the person only if they still have not read it (and it was not taken back). */
final class SendMentionMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly int $messageId, public readonly int $userId) {}

    public function handle(Chat $chat): void
    {
        $m = Message::query()->whereKey($this->messageId)->first();
        $to = User::query()->whereKey($this->userId)->first();
        $channel = $m !== null ? Channel::query()->whereKey($m->channel_id)->first() : null;
        if ($m === null || $to === null || $channel === null || $m->hidden_at !== null || ! Chat::allowed($to) || $chat->hasRead($channel->id, $to->id, $m->id)) {
            return;
        }
        $from = User::query()->whereKey($m->user_id)->first();
        $where = $channel->kind === 'direct' ? 'a direct message' : $channel->name;
        $to->notify(new MentionMail((string) ($from->name ?? 'Someone'), $where, mb_substr($m->body, 0, 300), '/chat?c='.$channel->ulid));
    }
}
