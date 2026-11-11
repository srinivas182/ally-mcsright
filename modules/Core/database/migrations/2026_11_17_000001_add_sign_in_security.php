<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sign-in security (specs/core/02, Sprint 4A): brokerage-wide 2FA requirement (interim column until
 * settings ship), passkey prompt state, trusted devices and known devices for new-device alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('tenants', function (Blueprint $table): void {
            $table->boolean('require_two_factor')->default(false);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('passkey_prompt_dismissed_at')->nullable();
        });

        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120);
            $table->string('ip', 45)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id'], 'trusted_devices_tenant_user_idx');
        });

        Schema::create('known_devices', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('fingerprint', 64);
            $table->string('label', 120);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint'], 'known_devices_user_fp_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
        Schema::dropIfExists('trusted_devices');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('passkey_prompt_dismissed_at'));
        Schema::connection(Connections::control())->table('tenants', fn (Blueprint $table) => $table->dropColumn('require_two_factor'));
    }
};
