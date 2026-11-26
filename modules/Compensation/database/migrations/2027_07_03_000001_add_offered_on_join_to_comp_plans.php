<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AG4: which commission plans a new agent can pick when they join online (for example revenue share or a flat fee).
 * None picked: they join on the default plan, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('comp_plans', function (Blueprint $table): void {
            $table->boolean('offered_on_join')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('comp_plans', function (Blueprint $table): void {
            $table->dropColumn('offered_on_join');
        });
    }
};
