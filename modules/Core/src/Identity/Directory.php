<?php

declare(strict_types=1);

namespace Modules\Core\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Which brokerages a sign-in email belongs to (specs/connect/03, M2b). Kept up to date as people are added, change
 * their email or leave. Emails are keyed hashes only; HQ staff are never listed.
 */
final class Directory
{
    public static function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public static function sync(User $user): void
    {
        $table = DB::connection(Connections::control())->table('identity_directory');
        if ($user->trashed() || $user->kind === UserKind::HqStaff || $user->email === '') {
            $table->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->delete();

            return;
        }
        $table->updateOrInsert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id],
            ['email_hash' => self::hash($user->email), 'updated_at' => now(), 'created_at' => now()]);
    }

    public static function forget(User $user): void
    {
        DB::connection(Connections::control())->table('identity_directory')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->delete();
    }

    /**
     * LOGIN1: where a brokerage's people sign in: its own verified back-office domain when it has one, otherwise its
     * RightAlly address ({slug}.rightally.app), which always works.
     */
    public static function loginUrl(Tenant $tenant, ?string $email = null): string
    {
        $primary = $tenant->primaryDomain();
        $host = $primary !== null && $primary->verification_status === VerificationStatus::Verified && $primary->ssl_status === SslStatus::Active
            ? $primary->host : Host::platformHostFor($tenant->slug);

        return 'https://'.$host.'/login'.($email !== null && $email !== '' ? '?'.http_build_query(['email' => $email]) : '');
    }

    /** @return list<Tenant> active brokerages where this email signs in */
    public static function brokeragesFor(string $email): array
    {
        $ids = DB::connection(Connections::control())->table('identity_directory')->where('email_hash', self::hash($email))->pluck('tenant_id')->all();

        return array_values(Tenant::query()->whereIn('id', $ids)->where('status', 'active')->where('type', '!=', 'hq')->orderBy('name')->get()->all());
    }
}
