<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Part 5 (specs/finance/02): W-9s with IRS TIN matching, and the "title pays agents directly" CDA option.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('pay_w9s', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable();     // an agent's own W-9
            $table->unsignedBigInteger('referral_id')->nullable(); // a referral payee's W-9, entered by finance
            $table->string('legal_name', 120);
            $table->string('business_name', 120)->nullable();
            $table->string('tax_class', 20); // individual, llc_single, llc_partnership, llc_corporation, c_corp, s_corp, partnership
            $table->char('tin_type', 3);     // ssn, ein
            $table->text('tin_sealed');
            $table->char('tin_last4', 4);
            $table->string('address', 200);
            $table->string('signature_name', 120);
            $table->dateTime('signed_at');
            $table->string('match_status', 12)->default('pending'); // pending, matched, mismatch, invalid
            $table->unsignedTinyInteger('match_code')->nullable();
            $table->dateTime('matched_at')->nullable();
            $table->string('status', 10)->default('current'); // current, replaced
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status'], 'pay_w9s_user_idx');
        });

        $cell->table('tx_settings', function (Blueprint $table): void {
            $table->boolean('cda_agents_direct')->default(false);
        });
        $cell->table('pay_recognitions', function (Blueprint $table): void {
            $table->json('paid_by_title')->nullable(); // agents paid directly by title: user id => cents
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('pay_recognitions', fn (Blueprint $t) => $t->dropColumn('paid_by_title'));
        $cell->table('tx_settings', fn (Blueprint $t) => $t->dropColumn('cda_agents_direct'));
        $cell->dropIfExists('pay_w9s');
    }
};
