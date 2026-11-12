<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Audit retention (with an end as well as a start) and legal holds (specs/core/04, Sprint 7A-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('audit_retention_years')->default(7);
        });

        Schema::connection(Connections::cell())->create('audit_legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('reason', 300);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('subject_label', 200)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('audit_legal_holds');
        Schema::connection(Connections::control())->table('tenants', fn (Blueprint $table) => $table->dropColumn('audit_retention_years'));
    }
};
