<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users belong to a tenant; email is unique per tenant (specs/core/02).
 * Also: invitations, and password-reset tokens keyed by tenant + email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('ulid')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('tenant_id')->nullable()->after('ulid');
            $table->string('kind', 20)->default('agent')->after('tenant_id');
            $table->string('first_name', 80)->default('')->after('kind');
            $table->string('last_name', 80)->default('')->after('first_name');
            $table->string('phone', 20)->nullable()->after('email_verified_at');
            $table->string('status', 20)->default('active')->after('password');
            $table->string('locale', 5)->nullable()->after('status');
            $table->string('timezone', 40)->nullable()->after('locale');
            $table->unsignedBigInteger('assisted_user_id')->nullable()->after('timezone');
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('last_active_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'kind', 'status']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });

        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('email', 254);
            $table->string('first_name', 80)->default('');
            $table->string('last_name', 80)->default('');
            $table->string('kind', 20);
            $table->json('role_ids');
            $table->unsignedBigInteger('assisted_user_id')->nullable();
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });

        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 280)->primary(); // "{tenant_id}|{email}"
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->default('');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropIndex(['tenant_id', 'kind', 'status']);
            $table->dropUnique(['ulid']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['ulid', 'tenant_id', 'kind', 'first_name', 'last_name', 'phone', 'status',
                'locale', 'timezone', 'assisted_user_id', 'last_login_at', 'last_active_at', 'created_by', 'deleted_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });
        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
