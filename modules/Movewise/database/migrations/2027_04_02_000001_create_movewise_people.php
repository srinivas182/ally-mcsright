<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW2 (specs/movewise/02): consumers. They are not tenant users: passwordless accounts (an email code),
 * saved homes and searches with alerts, the move planner, and AIlly conversations, all on the control plane. A phone
 * number is never needed. Sign-in sessions keep only a hash of the browser's token.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->create('movewise_people', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('email', 190)->unique();
            $table->string('name', 120)->nullable();
            $table->char('locale', 2)->default('en');
            $table->json('plan')->nullable();                    // the move planner: dates, budget, checked items
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        $db->create('movewise_sign_ins', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 190)->index();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
        $db->create('movewise_sessions', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->unsignedBigInteger('person_id')->index();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
        $db->create('movewise_saved_homes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->char('listing_ulid', 26);
            $table->timestamp('created_at');
            $table->unique(['person_id', 'listing_ulid']);
        });
        $db->create('movewise_saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('person_id')->index();
            $table->string('name', 120);
            $table->json('filters');
            $table->string('frequency', 8)->default('daily');   // daily | weekly | off
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
        $db->create('movewise_chats', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->char('locale', 2)->default('en');
            $table->json('messages');                            // [{role, text, at}], newest last, capped
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['movewise_chats', 'movewise_saved_searches', 'movewise_saved_homes', 'movewise_sessions', 'movewise_sign_ins', 'movewise_people'] as $t) {
            Schema::connection(Connections::control())->dropIfExists($t);
        }
    }
};
