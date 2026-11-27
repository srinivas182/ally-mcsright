<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AG4: the commission plan the new agent picked when they joined (placed on it once they are in). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('recruiting_join_applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('comp_plan_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('recruiting_join_applications', function (Blueprint $table): void {
            $table->dropColumn('comp_plan_id');
        });
    }
};
