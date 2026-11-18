<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * One shape for an inbound email, whichever provider received it (DQ4, specs/crm/05): Postmark inbound JSON
 * (recommended), SendGrid Inbound Parse or Mailgun Routes, as chosen in Outside services → Forward-paperwork email.
 * Attachments: PDFs and images only, at most 20, each up to 25 MB.
 *
 * @phpstan-type Email array{recipients: string, from: string, reply_to: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}
 */
final class InboundPayload
{
    private const MAX_FILES = 20;

    private const MAX_BYTES = 25 * 1024 * 1024;

    /** @return Email */
    public static function postmark(Request $r): array
    {
        $attachments = [];
        foreach (array_slice((array) $r->input('Attachments', []), 0, self::MAX_FILES) as $a) {
            $bytes = is_array($a) ? base64_decode((string) ($a['Content'] ?? ''), true) : false;
            if (is_array($a) && is_string($bytes)) {
                self::keep($attachments, (string) ($a['Name'] ?? 'attachment'), (string) ($a['ContentType'] ?? ''), $bytes);
            }
        }

        return self::shape(implode(' ', array_filter([(string) $r->input('OriginalRecipient', ''), (string) $r->input('To', ''),
            ...array_map(static fn ($x): string => is_array($x) ? (string) ($x['Email'] ?? '') : '', (array) $r->input('ToFull', []))])),
            (string) $r->input('From', ''), (string) $r->input('ReplyTo', ''), (string) $r->input('Subject', ''),
            (string) ($r->input('TextBody') ?: $r->input('HtmlBody', '')), (string) $r->input('MessageID', ''), $attachments);
    }

    /**
     * SendGrid Inbound Parse (multipart form; attachments as files).
     *
     * @return Email
     */
    public static function sendgrid(Request $r): array
    {
        $envelope = (array) json_decode((string) $r->input('envelope', '{}'), true);
        $info = (array) json_decode((string) $r->input('attachment-info', '{}'), true);
        $attachments = [];
        for ($i = 1; $i <= min(self::MAX_FILES, (int) $r->input('attachments', 0)); $i++) {
            $file = $r->file('attachment'.$i);
            if ($file instanceof UploadedFile) {
                $meta = (array) ($info['attachment'.$i] ?? []);
                self::keep($attachments, (string) ($meta['filename'] ?? $file->getClientOriginalName()), (string) ($meta['type'] ?? $file->getClientMimeType()),
                    (string) file_get_contents($file->getRealPath()));
            }
        }
        preg_match('/^Message-I[Dd]:\s*(\S+)/mi', (string) $r->input('headers', ''), $mid);

        return self::shape(implode(' ', array_filter([(string) $r->input('to', ''), ...array_map('strval', (array) ($envelope['to'] ?? []))])),
            (string) $r->input('from', ''), '', (string) $r->input('subject', ''), (string) ($r->input('text') ?: strip_tags((string) $r->input('html', ''))),
            (string) ($mid[1] ?? ''), $attachments);
    }

    /**
     * Mailgun Routes (forward to URL; multipart form).
     *
     * @return Email
     */
    public static function mailgun(Request $r): array
    {
        $attachments = [];
        for ($i = 1; $i <= min(self::MAX_FILES, (int) $r->input('attachment-count', 0)); $i++) {
            $file = $r->file('attachment-'.$i);
            if ($file instanceof UploadedFile) {
                self::keep($attachments, $file->getClientOriginalName(), $file->getClientMimeType(), (string) file_get_contents($file->getRealPath()));
            }
        }

        return self::shape(implode(' ', array_filter([(string) $r->input('recipient', ''), (string) $r->input('To', '')])), (string) $r->input('from', $r->input('sender', '')),
            (string) $r->input('Reply-To', ''), (string) $r->input('subject', ''), (string) ($r->input('body-plain') ?: strip_tags((string) $r->input('body-html', ''))),
            (string) $r->input('Message-Id', ''), $attachments);
    }

    /** Mailgun's signature: HMAC-SHA256 of timestamp + token with the webhook signing key, within 15 minutes. */
    public static function mailgunSigned(Request $r, string $signingKey): bool
    {
        $ts = (string) $r->input('timestamp', '');
        $sig = (string) $r->input('signature', '');

        return $signingKey !== '' && ctype_digit($ts) && abs(time() - (int) $ts) <= 900 && $sig !== ''
            && hash_equals(hash_hmac('sha256', $ts.$r->input('token', ''), $signingKey), $sig);
    }

    /** @param  list<array{name: string, type: string, content: string}>  $out */
    private static function keep(array &$out, string $name, string $type, string $bytes): void
    {
        $type = strtolower(trim(explode(';', $type)[0]));
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || ! (str_contains($type, 'pdf') || str_starts_with($type, 'image/') || str_ends_with(strtolower($name), '.pdf'))) {
            return;
        }
        $out[] = ['name' => mb_substr($name, 0, 200), 'type' => $type, 'content' => $bytes];
    }

    /**
     * @param  list<array{name: string, type: string, content: string}>  $attachments
     * @return Email
     */
    private static function shape(string $recipients, string $from, string $replyTo, string $subject, string $text, string $messageId, array $attachments): array
    {
        return ['recipients' => $recipients, 'from' => mb_substr($from, 0, 200), 'reply_to' => mb_substr($replyTo, 0, 200), 'subject' => mb_substr($subject, 0, 300),
            'text' => mb_substr($text, 0, 20000), 'message_id' => mb_substr($messageId, 0, 100) ?: null, 'attachments' => $attachments];
    }
}
