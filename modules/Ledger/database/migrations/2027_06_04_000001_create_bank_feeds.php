<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * BK1 (specs/finance/20, B15): a live, read-only bank feed for the operating account (Plaid, MX or Teller). Money never
 * moves through it; it only brings transactions in for the monthly reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('acct_bank_feeds', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('provider', 12);
            $table->string('mode', 5); // test, live (the outside service's mode when connected)
            $table->text('access'); // encrypted JSON: the provider's access token or user id
            $table->string('account_id', 120)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->string('mask', 8)->nullable();
            $table->text('cursor')->nullable();
            $table->bigInteger('balance_cents')->nullable();
            $table->dateTime('balance_at')->nullable();
            $table->string('status', 12)->default('choose'); // choose (pick the account), active, error
            $table->string('error', 200)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->unsignedBigInteger('connected_by');
            $table->timestamps();
            $table->unique('tenant_id');
        });
        $cell->create('acct_bank_feed_txns', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('feed_id');
            $table->string('external_id', 120);
            $table->date('posted_on');
            $table->bigInteger('amount_cents'); // + money in, − money out
            $table->string('description', 300);
            $table->boolean('pending')->default(false);
            $table->timestamps();
            $table->unique(['feed_id', 'external_id']);
            $table->index(['tenant_id', 'posted_on']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('acct_bank_feed_txns');
        $cell->dropIfExists('acct_bank_feeds');
    }
};
