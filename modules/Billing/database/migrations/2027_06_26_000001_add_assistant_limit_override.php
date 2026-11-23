<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AO1: HQ can give one brokerage more (or fewer) website assistant conversations a month than its package includes. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('usage_tenant_policies', function (Blueprint $table): void {
            $table->unsignedInteger('ailly_monthly')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('usage_tenant_policies', function (Blueprint $table): void {
            $table->dropColumn('ailly_monthly');
        });
    }
};
