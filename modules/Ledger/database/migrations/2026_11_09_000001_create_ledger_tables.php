<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Double-entry ledger (specs/finance/01). Journals and lines are append-only:
 * on MySQL, triggers block UPDATE/DELETE of lines and DELETE of journals, and the only
 * permitted journal update is setting reversed_by_id once. Models enforce the same rules.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('code', 60);
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('normal_side', 10);
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('subledger_type', 30)->nullable();
            $table->unsignedBigInteger('subledger_id')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'subledger_type', 'subledger_id']);
        });

        $schema->create('ledger_periods', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'starts_on']);
        });

        $schema->create('ledger_journals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('journal_type', 60);
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 40)->nullable();
            $table->string('posting_key', 191);
            $table->date('effective_date');
            $table->dateTime('posted_at', 6);
            $table->string('description', 255)->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
            $table->unsignedBigInteger('reversed_by_id')->nullable();
            $table->foreignId('period_id')->constrained('ledger_periods')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['tenant_id', 'posting_key']);
            $table->index(['tenant_id', 'effective_date']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        $schema->create('ledger_lines', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('debit_cents')->default(0);
            $table->unsignedBigInteger('credit_cents')->default(0);
            $table->char('currency', 3);
            $table->string('memo', 255)->nullable();
            $table->unsignedBigInteger('dimension_office_id')->nullable();
            $table->unsignedBigInteger('dimension_team_id')->nullable();

            $table->index(['tenant_id', 'account_id']);
        });

        $schema->create('ledger_balances', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained('ledger_accounts')->restrictOnDelete();
            $table->tenant();
            $table->char('currency', 3);
            $table->bigInteger('net_debit_cents')->default(0);
            $table->unsignedBigInteger('last_journal_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        if ($schema->getConnection()->getDriverName() === 'mysql') {
            $db = DB::connection($this->getConnection());
            $db->unprepared("CREATE TRIGGER ledger_lines_no_update BEFORE UPDATE ON ledger_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_lines are append-only'");
            $db->unprepared("CREATE TRIGGER ledger_lines_no_delete BEFORE DELETE ON ledger_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_lines are append-only'");
            $db->unprepared("CREATE TRIGGER ledger_journals_no_delete BEFORE DELETE ON ledger_journals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_journals are append-only'");
            $db->unprepared("CREATE TRIGGER ledger_journals_reverse_once BEFORE UPDATE ON ledger_journals FOR EACH ROW BEGIN IF OLD.reversed_by_id IS NOT NULL OR NEW.posting_key <> OLD.posting_key OR NEW.tenant_id <> OLD.tenant_id OR NEW.effective_date <> OLD.effective_date OR NEW.period_id <> OLD.period_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_journals are immutable except reversed_by_id once'; END IF; END");
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (['ledger_balances', 'ledger_lines', 'ledger_journals', 'ledger_periods', 'ledger_accounts'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
