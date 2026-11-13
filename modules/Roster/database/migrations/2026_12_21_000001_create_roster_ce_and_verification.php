<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Roster part 3 (specs/roster/01): continuing education rules and courses; automatic verification checks. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('roster_ce_rules', function (Blueprint $table): void {
            $table->tenant();
            $table->char('state_code', 2);
            $table->decimal('hours_required', 5, 1);
            $table->unsignedSmallInteger('cycle_months');
            $table->json('topics'); // [{topic, hours}]
            $table->timestamps();
            $table->primary(['tenant_id', 'state_code']);
        });

        $schema->create('roster_ce_courses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('state_code', 2);
            $table->string('title', 160);
            $table->string('provider', 120)->nullable();
            $table->string('topic', 60)->nullable();
            $table->decimal('hours', 5, 1);
            $table->date('completed_on');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('logged_by');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'state_code'], 'roster_ce_user_idx');
        });

        $schema->table('roster_credentials', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ce_reminded_days')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->string('check_result', 12)->nullable(); // active, inactive, not_found, mismatch
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('roster_credentials', fn (Blueprint $t) => $t->dropColumn(['ce_reminded_days', 'last_checked_at', 'check_result']));
        $schema->dropIfExists('roster_ce_courses');
        $schema->dropIfExists('roster_ce_rules');
    }
};
