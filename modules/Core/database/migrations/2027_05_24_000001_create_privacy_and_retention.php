<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * TR1 (specs/core/18), brokerage database: privacy requests from the public (see, delete, correct, stop selling or
 * sharing), confirmed by email, with a 45-day answer date; and how long each kind of data is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('kind', 8); // know, delete, correct, opt_out
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('phone', 30)->nullable();
            $table->text('details')->nullable();
            $table->string('status', 11)->default('unconfirmed'); // unconfirmed, open, done, declined
            $table->char('token_hash', 64)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->string('outcome', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        $cell->create('retention_rules', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('kind', 40);
            $table->unsignedSmallInteger('months')->nullable(); // null = keep
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->unsignedInteger('last_removed')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('retention_rules');
        $cell->dropIfExists('privacy_requests');
    }
};
