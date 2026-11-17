<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AC2 (specs/finance/21): the brokerage as payer on its 1099s, and each e-filing sent to the IRS through the partner. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('pay_1099_payer', function (Blueprint $table): void {
            $table->tenant();
            $table->string('legal_name', 120);
            $table->text('ein_sealed');
            $table->char('ein_last4', 4);
            $table->string('street', 120);
            $table->string('city', 60);
            $table->char('state', 2);
            $table->string('zip', 10);
            $table->string('phone', 20)->nullable();
            $table->timestamps();
            $table->primary('tenant_id');
        });
        $cell->create('pay_1099_filings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedSmallInteger('year');
            $table->string('status', 16); // transmitted, accepted, rejected, failed
            $table->string('submission_id', 80)->nullable();
            $table->unsignedInteger('records');
            $table->unsignedBigInteger('total_cents');
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('filed_by');
            $table->timestamps();
            $table->index(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('pay_1099_filings');
        Schema::connection(Connections::cell())->dropIfExists('pay_1099_payer');
    }
};
