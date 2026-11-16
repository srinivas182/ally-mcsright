<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * The notification centre and custom fields (specs/core/10, N1).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('user_notices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 80);
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('urgent')->default(false);
            $table->boolean('email_pending')->default(false); // held for quiet hours or the daily digest
            $table->dateTime('read_at')->nullable();
            $table->dateTime('snoozed_until')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'user_id', 'read_at'], 'user_notices_unread_idx');
            $table->index(['email_pending', 'user_id'], 'user_notices_pending_idx');
        });

        $schema->create('notice_prefs', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 80);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->primary(['user_id', 'type']);
        });

        $schema->create('notice_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedTinyInteger('quiet_start')->nullable(); // hour 0-23, in the person's time zone
            $table->unsignedTinyInteger('quiet_end')->nullable();
            $table->boolean('digest')->default(false); // one email a day at 7am instead of one per alert
            $table->dateTime('digest_sent_at')->nullable();
        });

        $schema->create('custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('entity', 20); // contact, deal, agent
            $table->string('key', 60);
            $table->string('label', 80);
            $table->string('type', 20); // text, number, money, date, choice, multi, yesno, url
            $table->json('options')->nullable();
            $table->boolean('required_to_close')->default(false);
            $table->string('hidden_from', 20)->nullable(); // a hideable field group (commission, bank) whose permission reveals it
            $table->string('help', 200)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'entity', 'key'], 'custom_fields_key_unq');
        });

        $schema->create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');
            $table->unique(['field_id', 'entity_id'], 'custom_field_values_unq');
            $table->index(['tenant_id', 'entity_id'], 'custom_field_values_entity_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['custom_field_values', 'custom_fields', 'notice_settings', 'notice_prefs', 'user_notices'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
