<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AR2 (specs/network/02): the referring agent's rating, and fee disputes, for the Referral Trust Score. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection(Connections::control())->table('network_referrals', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rating')->nullable(); // 1-5, from the referring agent
            $table->string('rating_note', 300)->nullable();
            $table->dateTime('rated_at')->nullable();
            $table->dateTime('disputed_at')->nullable(); // the referring side says the fee never arrived
            $table->string('dispute_note', 300)->nullable();
            $table->string('dispute_reply', 300)->nullable();
            $table->dateTime('dispute_closed_at')->nullable(); // HQ closed it after both sides agreed
        });
        Schema::connection(Connections::control())->table('network_profiles', function (Blueprint $table): void {
            $table->dateTime('trust_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('network_referrals', function (Blueprint $table): void {
            $table->dropColumn(['rating', 'rating_note', 'rated_at', 'disputed_at', 'dispute_note', 'dispute_reply', 'dispute_closed_at']);
        });
        Schema::connection(Connections::control())->table('network_profiles', function (Blueprint $table): void {
            $table->dropColumn('trust_at');
        });
    }
};
