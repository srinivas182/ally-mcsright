<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AR1 (specs/network/01): the agent referral network. It lives in the control database because referrals go between
 * brokerages; client details are encrypted and shared only after the receiving agent accepts.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->create('network_brokerages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->decimal('default_fee_percent', 5, 2)->default(25);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $control->create('network_profiles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code', 40)->unique(); // for the public page
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 160);
            $table->string('brokerage', 160);
            $table->string('state_code', 2);
            $table->string('licence', 40)->nullable();
            $table->json('areas'); // list of "City, ST" or ZIP codes
            $table->string('areas_text', 1000); // lower-case, for search
            $table->string('sides', 8)->default('both'); // buyers, sellers, both
            $table->json('languages');
            $table->string('bio', 500)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('share_phone')->default(false);
            $table->boolean('public')->default(false);
            $table->string('status', 8)->default('active'); // active, paused
            $table->dateTime('verified_at')->nullable(); // the broker checked the licence
            $table->unsignedTinyInteger('trust_score')->nullable(); // AR2
            $table->json('trust_parts')->nullable();
            $table->unsignedInteger('trust_count')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['status', 'state_code']);
        });
        $control->create('network_referrals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('from_profile_id');
            $table->unsignedBigInteger('to_profile_id');
            $table->string('side', 8); // buyer, seller
            $table->string('area', 120);
            $table->string('price_band', 40)->nullable();
            $table->string('timing', 60)->nullable();
            $table->string('language', 30)->nullable();
            $table->string('needs', 600)->nullable();
            $table->text('client')->nullable(); // encrypted JSON; removed when declined or expired
            $table->dateTime('consent_at');
            $table->decimal('fee_percent', 5, 2);
            $table->string('status', 16)->default('sent'); // sent, accepted, declined, expired, under_contract, closed, fell_through
            $table->string('reason', 200)->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->string('agreed_by', 160)->nullable();
            $table->string('agreement', 600)->nullable();
            $table->string('deal_ulid', 26)->nullable(); // the receiving brokerage's deal
            $table->unsignedBigInteger('fee_due_cents')->nullable();
            $table->date('closed_on')->nullable();
            $table->dateTime('fee_sent_at')->nullable(); // the receiving side says the fee was sent
            $table->dateTime('fee_paid_at')->nullable(); // the sending side confirms it arrived
            $table->dateTime('last_update_at')->nullable();
            $table->timestamps();
            $table->index(['to_profile_id', 'status']);
            $table->index(['from_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->dropIfExists('network_referrals');
        $control->dropIfExists('network_profiles');
        $control->dropIfExists('network_brokerages');
    }
};
