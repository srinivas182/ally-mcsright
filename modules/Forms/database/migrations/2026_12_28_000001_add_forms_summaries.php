<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Forms part 5b (specs/forms/01): plain-English packet summaries (agent-approved, tied to the values they were
 * written from) and the clause check (special terms not from the brokerage's clause library).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->table('form_packets', function (Blueprint $table): void {
            $table->text('summary')->nullable(); // encrypted JSON: en + es
            $table->char('summary_hash', 64)->nullable(); // hash of the values it was written from
            $table->dateTime('summary_generated_at')->nullable();
            $table->unsignedBigInteger('summary_approved_by')->nullable();
            $table->dateTime('summary_approved_at')->nullable();
            $table->unsignedBigInteger('clauses_approved_by')->nullable();
            $table->char('clauses_approved_hash', 64)->nullable();
        });

        $schema->table('form_settings', function (Blueprint $table): void {
            $table->string('clause_review', 8)->default('warn'); // off, warn, require
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('form_settings', fn (Blueprint $t) => $t->dropColumn('clause_review'));
        $schema->table('form_packets', fn (Blueprint $t) => $t->dropColumn(['summary', 'summary_hash', 'summary_generated_at', 'summary_approved_by',
            'summary_approved_at', 'clauses_approved_by', 'clauses_approved_hash']));
    }
};
