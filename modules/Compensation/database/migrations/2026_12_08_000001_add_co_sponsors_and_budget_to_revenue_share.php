<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Co-sponsorship and the brokerage budget guard (specs/compensation/02, additions 2, 3, 15).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('rs_sponsorships', function (Blueprint $table): void {
            $table->unsignedBigInteger('co_sponsor_user_id')->nullable();
            $table->decimal('co_share_percent', 5, 2)->nullable();
        });
        $schema->table('rs_settings', function (Blueprint $table): void {
            $table->decimal('budget_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('budget_warned_year')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('rs_settings', fn (Blueprint $t) => $t->dropColumn(['budget_percent', 'budget_warned_year']));
        $schema->table('rs_sponsorships', fn (Blueprint $t) => $t->dropColumn(['co_sponsor_user_id', 'co_share_percent']));
    }
};
