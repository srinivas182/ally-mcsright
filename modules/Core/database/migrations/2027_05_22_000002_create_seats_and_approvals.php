<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * FL1 (specs/core/16), brokerage database: who holds a seat in a seat-limited area, and approval rules for sensitive
 * changes (how many people must say yes), with the requests and each person's answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('seat_holders', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('area', 40);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'area', 'user_id']);
        });
        $cell->create('approval_rules', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('action', 60);
            $table->unsignedTinyInteger('needed')->default(1); // 0 = no approval needed
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'action']);
        });
        $cell->create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('action', 60);
            $table->string('about', 200);
            $table->json('payload');
            $table->unsignedTinyInteger('needed');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 9)->default('waiting'); // waiting, approved, declined, expired, failed
            $table->string('result', 300)->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        $cell->create('approval_votes', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('user_id');
            $table->string('decision', 7); // approve, decline
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['approval_votes', 'approval_requests', 'approval_rules', 'seat_holders'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
