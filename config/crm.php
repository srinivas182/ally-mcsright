<?php

declare(strict_types=1);

return [
    /*
     * Portal lead emails (specs/grow/04): brokerages forward to leads-<token>@<domain>; the inbound-email
     * provider (Postmark inbound format) posts each email to /webhooks/inbound/lead-email with this secret as
     * the basic-auth password.
     */
    'inbound_email_domain' => env('CRM_INBOUND_EMAIL_DOMAIN', 'inbound.rightally.app'),
    'inbound_email_secret' => env('CRM_INBOUND_EMAIL_SECRET'),
];
