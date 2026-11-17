<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use App\Models\User;
use Modules\Core\Tenancy\TenantContext;

/** The signature without a brand kit: name, brokerage, phone and email. */
final class PlainEmailSignature implements EmailSignature
{
    public function text(User $user): string
    {
        return implode("\n", array_filter([$user->name, app(TenantContext::class)->check() ? app(TenantContext::class)->current()->name : null,
            (string) ($user->getAttribute('phone') ?? ''), (string) $user->email], fn (?string $l): bool => $l !== null && $l !== ''));
    }

    public function html(User $user): string
    {
        return '<p style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222">'.nl2br(e($this->text($user))).'</p>';
    }
}
