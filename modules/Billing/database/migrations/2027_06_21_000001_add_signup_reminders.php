<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** SU1: reminders to people who started signing up but did not pay, and their "don't remind me" choice. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->dateTime('reminded_at')->nullable();
            $table->boolean('no_reminders')->default(false);
            $table->index(['status', 'created_at'], 'signups_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->dropIndex('signups_status_created_idx');
            $table->dropColumn(['reminders_sent', 'reminded_at', 'no_reminders']);
        });
    }
};
