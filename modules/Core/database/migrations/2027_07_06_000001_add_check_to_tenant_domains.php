<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** DM2: when a custom domain was last checked, and what is still missing in plain words. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('tenant_domains', function (Blueprint $table): void {
            $table->dateTime('checked_at')->nullable();
            $table->string('check_message', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('tenant_domains', function (Blueprint $table): void {
            $table->dropColumn(['checked_at', 'check_message']);
        });
    }
};
