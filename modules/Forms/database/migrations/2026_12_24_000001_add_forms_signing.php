<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Forms part 2 (specs/forms/01): signing, events, sealing. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->table('form_packets', function (Blueprint $table): void {
            $table->string('verify', 6)->default('email'); // email, sms
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('sealed_path', 300)->nullable();
            $table->char('sealed_sha256', 64)->nullable()->index();
            $table->text('seal_signature')->nullable();
            $table->string('void_reason', 200)->nullable();
        });

        $schema->table('form_signers', function (Blueprint $table): void {
            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('viewed_at')->nullable();
            $table->dateTime('consent_at')->nullable();
            $table->char('sms_code_hash', 64)->nullable();
            $table->unsignedTinyInteger('sms_attempts')->default(0);
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signature_text', 160)->nullable();
            $table->string('signature_image', 300)->nullable(); // stored PNG of a drawn signature
            $table->string('initials', 12)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->unsignedTinyInteger('reminders')->default(0);
            $table->dateTime('reminded_at')->nullable();
            $table->unsignedBigInteger('hosted_by')->nullable(); // in-person signing on an agent's device
        });

        $schema->create('form_events', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('packet_id')->constrained('form_packets')->cascadeOnDelete();
            $table->unsignedBigInteger('signer_id')->nullable();
            $table->string('action', 30);
            $table->string('detail', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('form_events');
        $schema->table('form_signers', fn (Blueprint $t) => $t->dropUnique(['token_hash']));
        $schema->table('form_packets', fn (Blueprint $t) => $t->dropIndex(['sealed_sha256']));
        $schema->table('form_signers', fn (Blueprint $t) => $t->dropColumn(['token_hash', 'sent_at', 'viewed_at', 'consent_at', 'sms_code_hash', 'sms_attempts', 'verified_at',
            'signed_at', 'signature_text', 'signature_image', 'initials', 'ip', 'user_agent', 'decline_reason', 'reminders', 'reminded_at', 'hosted_by']));
        $schema->table('form_packets', fn (Blueprint $t) => $t->dropColumn(['verify', 'sent_at', 'completed_at', 'sealed_path', 'sealed_sha256', 'seal_signature', 'void_reason']));
    }
};
