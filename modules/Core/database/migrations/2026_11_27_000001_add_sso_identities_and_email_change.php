<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google/Microsoft sign-in identities (linked by the provider's permanent subject ID) and
 * re-verified email changes (Sprint 4A-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 20);
            $table->string('subject', 191);
            $table->string('email', 254)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'subject'], 'user_identities_subject_unq');
            $table->index(['tenant_id', 'user_id'], 'user_identities_user_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('pending_email', 254)->nullable();
            $table->char('pending_email_token', 64)->nullable();
            $table->dateTime('pending_email_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['pending_email', 'pending_email_token', 'pending_email_expires_at']));
        Schema::dropIfExists('user_identities');
    }
};
