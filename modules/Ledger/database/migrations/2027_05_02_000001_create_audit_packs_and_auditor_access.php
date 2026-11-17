<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** FH2 (specs/finance/23): audit packs, and read-only access for outside auditors that ends by itself. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('ledger_audit_packs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 8)->default('queued'); // queued, running, ready, failed, expired
            $table->string('path', 191)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('password')->nullable(); // encrypted
            $table->unsignedInteger('files')->nullable();
            $table->string('error', 300)->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'ledger_packs_status');
        });
        $cell->create('ledger_auditor_access', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('email', 191);
            $table->string('name', 160);
            $table->string('firm', 160)->default('');
            $table->unsignedBigInteger('invitation_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('expires_at');
            $table->unsignedBigInteger('granted_by');
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'email'], 'ledger_auditors_email');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('ledger_auditor_access');
        $cell->dropIfExists('ledger_audit_packs');
    }
};
