<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Offices, teams, roles and grants (specs/core/03, Sprint 8A).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('offices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 120);
            $table->string('city', 80)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->string('licence_number', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->string('name', 120);
            $table->unsignedBigInteger('leader_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('team_members', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->dateTime('joined_at');
            $table->primary(['team_id', 'user_id']);
            $table->index(['tenant_id', 'user_id'], 'team_members_user_idx');
        });

        $schema->create('user_offices', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->primary(['user_id', 'office_id']);
        });

        $schema->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('key', 60);
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->string('template_key', 40)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'key'], 'roles_key_unq');
        });

        $schema->create('role_permissions', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission_key', 80);
            $table->string('scope', 10)->nullable(); // own, team, office, tenant
            $table->primary(['role_id', 'permission_key']);
        });

        $schema->create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->dateTime('granted_at');
            $table->dateTime('expires_at')->nullable();
            $table->string('reason', 200)->nullable();
            $table->unique(['user_id', 'role_id'], 'user_roles_unq');
            $table->index(['tenant_id', 'user_id'], 'user_roles_user_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['user_roles', 'role_permissions', 'roles', 'user_offices', 'team_members', 'teams', 'offices'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
