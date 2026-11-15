<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Proof of consent that travels with every contact (specs/grow/11): where each consent or opt-out came from (web
 * form, website chat, import, a connected app, an agent, a text keyword), its reference (page, app, file) and the
 * evidence kept at the time, with a fingerprint so it can be shown to be unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('crm_consent_events', function (Blueprint $table): void {
            $table->string('source', 20)->nullable()->after('method'); // web_form | chat | import | connected_app | agent | sms_keyword
            $table->string('source_ref', 300)->nullable()->after('source');
            $table->text('evidence')->nullable()->after('source_ref'); // JSON: what the person saw and sent
            $table->char('evidence_sha256', 64)->nullable()->after('evidence');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('crm_consent_events', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_ref', 'evidence', 'evidence_sha256']);
        });
    }
};
