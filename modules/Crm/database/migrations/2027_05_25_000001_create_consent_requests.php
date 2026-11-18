<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** CN1 (specs/grow/18): one-tap texting consent requests sent by email, and the person's answer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('crm_consent_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('phone', 20);
            $table->string('email', 254);
            $table->string('wording', 600);
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('sent_by');
            $table->dateTime('sent_at');
            $table->dateTime('expires_at');
            $table->string('answer', 3)->nullable(); // yes, no
            $table->dateTime('answered_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('crm_consent_requests');
    }
};
