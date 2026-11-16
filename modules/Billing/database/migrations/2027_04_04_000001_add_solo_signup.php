<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SO1, RightAlly for Agents (specs/grow/03): packages for brokerages or for solo agents, and which kind of RightAlly a
 * signup creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('billing_packages', function (Blueprint $table): void {
            $table->string('audience', 10)->default('brokerage')->after('name'); // brokerage, agent
        });
        $control->table('signups', function (Blueprint $table): void {
            $table->string('type', 10)->default('brokerage')->after('ulid'); // brokerage, solo
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('signups', fn (Blueprint $table) => $table->dropColumn('type'));
        Schema::connection(Connections::control())->table('billing_packages', fn (Blueprint $table) => $table->dropColumn('audience'));
    }
};
