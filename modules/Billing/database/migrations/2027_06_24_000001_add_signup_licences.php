<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LV1: the real estate licences given at sign-up (state, number) and what the automatic check found, so HQ can see
 * which were confirmed in state records and which need checking by hand.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->text('licences')->nullable(); // JSON: [{state, number, status, method, review, checked_at}]
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->dropColumn('licences');
        });
    }
};
