<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** DS1 (specs/core/12): brokerage exports and restores, kept in the control plane. */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection(Connections::control());
        $s->create('brokerage_exports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('kind', 10); // portable (owner download), backup (HQ, restorable), safety (taken before a restore)
            $table->string('status', 10)->default('queued'); // queued, running, ready, failed, expired
            $table->string('path', 200)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('password')->nullable(); // encrypted; the ZIP is AES-256 protected
            $table->json('tables')->nullable(); // table => rows
            $table->unsignedInteger('files')->default(0);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_name', 160)->nullable();
            $table->string('error', 300)->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
        });
        $s->create('brokerage_restores', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('export_id');
            $table->string('status', 10)->default('pending'); // pending (needs a second person), approved, running, done, failed, cancelled
            $table->string('reason', 300);
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('safety_export_id')->nullable();
            $table->json('result')->nullable();
            $table->string('error', 300)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection(Connections::control());
        $s->dropIfExists('brokerage_restores');
        $s->dropIfExists('brokerage_exports');
    }
};
