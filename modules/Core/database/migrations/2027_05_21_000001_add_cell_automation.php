<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** PS1 (specs/core/14): cells that fill up close to new brokerages on their own, and HQ is told before it happens. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('cells', function (Blueprint $table): void {
            $table->boolean('auto_full')->default(false); // closed to new brokerages by the watcher, not by a person
            $table->dateTime('warned_at')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->json('last_check')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('cells', function (Blueprint $table): void {
            $table->dropColumn(['auto_full', 'warned_at', 'checked_at', 'last_check']);
        });
    }
};
