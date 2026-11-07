<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Provisioning idempotency key, closing timestamps, and redirects from old platform hosts
 * after a slug change (specs/core/01).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('tenants', function (Blueprint $table): void {
            $table->string('provisioning_key', 100)->nullable()->unique()->after('slug');
            $table->dateTime('closing_at')->nullable()->after('suspended_reason');
            $table->string('closing_reason', 255)->nullable()->after('closing_at');
            $table->dateTime('purge_due_notified_at')->nullable()->after('purge_after');
        });

        $schema->create('tenant_host_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('host', 253)->unique();
            $table->dateTime('redirect_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('tenant_host_redirects');
        $schema->table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['provisioning_key']);
            $table->dropColumn(['provisioning_key', 'closing_at', 'closing_reason', 'purge_due_notified_at']);
        });
    }
};
