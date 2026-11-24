<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * CM4: every agent's own join link (a short code), and the applications made through it: the agent applies with a
 * valid licence, pays for their pack, and is placed under their sponsor automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('join_code', 12)->nullable();
            $table->index(['tenant_id', 'join_code'], 'users_join_code_idx');
        });
        Schema::connection(Connections::cell())->create('recruiting_join_applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('sponsor_user_id')->nullable();
            $table->unsignedBigInteger('agent_plan_id')->nullable();
            $table->char('licence_state', 2);
            $table->string('licence_number', 40);
            $table->string('licence_status', 12);
            $table->string('status', 16)->default('payment'); // payment, joined
            $table->dateTime('joined_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id'], 'join_app_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('recruiting_join_applications');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_join_code_idx');
            $table->dropColumn('join_code');
        });
    }
};
