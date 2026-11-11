<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Contact imports, merges and the consent history (specs/crm/01, Sprint 7D).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('crm_imports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('file_path', 255);
            $table->string('original_name', 200);
            $table->string('status', 20); // uploaded, running, done, failed
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->unsignedBigInteger('import_id')->nullable();
        });

        $schema->create('crm_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('channel', 10); // sms, email, call
            $table->string('action', 10); // opt_in, opt_out
            $table->string('method', 20); // web_form, signed, verbal, keyword, agent, merge
            $table->text('consent_text')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('occurred_at');

            $table->index(['tenant_id', 'contact_id', 'channel', 'occurred_at'], 'crm_consent_contact_idx');
        });

        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->string('sms_consent_method', 20)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('crm_contacts', fn (Blueprint $table) => $table->dropColumn(['merged_into_id', 'import_id', 'sms_consent_method']));
        $schema->dropIfExists('crm_consent_events');
        $schema->dropIfExists('crm_imports');
    }
};
