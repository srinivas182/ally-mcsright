<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * RF1 (specs/billing/08): RightAlly's own referral programme. People (coaches, influencers) and brokerages refer
 * new brokerages; rewards wait 30 days, then HQ pays them. Control plane: it spans brokerages.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $s = Schema::connection($this->getConnection());
        $s->create('affiliates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('kind', 10); // person, brokerage
            $table->string('code', 24)->unique();
            $table->string('name', 160);
            $table->string('email', 190);
            $table->unsignedBigInteger('tenant_id')->nullable()->unique(); // the referring brokerage, for kind brokerage
            $table->char('token_hash', 64)->nullable(); // the person's dashboard link
            $table->string('status', 8)->default('active'); // active, paused
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        $s->create('affiliate_clicks', function (Blueprint $table): void {
            $table->unsignedBigInteger('affiliate_id');
            $table->date('day');
            $table->unsignedInteger('clicks')->default(0);
            $table->primary(['affiliate_id', 'day']);
        });
        $s->create('affiliate_referrals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('affiliate_id')->index();
            $table->unsignedBigInteger('tenant_id')->unique(); // the brokerage that signed up
            $table->string('status', 10)->default('pending'); // pending, approved, paid, void
            $table->unsignedBigInteger('paid_cents')->default(0); // what the brokerage paid in its first year
            $table->unsignedBigInteger('reward_cents')->default(0);
            $table->string('reward_kind', 8); // cash, credit
            $table->dateTime('first_paid_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
        $s->table('signups', function (Blueprint $table): void {
            $table->string('referral_code', 24)->nullable();
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->getConnection());
        $s->table('signups', fn (Blueprint $table) => $table->dropColumn('referral_code'));
        $s->dropIfExists('affiliate_referrals');
        $s->dropIfExists('affiliate_clicks');
        $s->dropIfExists('affiliates');
    }
};
