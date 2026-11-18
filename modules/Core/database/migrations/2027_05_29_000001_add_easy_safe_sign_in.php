<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SX3 (easy, safe sign-in): when each person's grace period for two-step sign-in ends, and "I lost my phone"
 * requests that the broker confirms before sign-in security is reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('second_factor_due_at')->nullable();
        });

        Schema::create('sign_in_help_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('reason', 20); // lost_device
            $table->string('status', 12)->default('waiting'); // waiting, reset, declined, expired
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'sign_in_help_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_help_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('second_factor_due_at');
        });
    }
};
