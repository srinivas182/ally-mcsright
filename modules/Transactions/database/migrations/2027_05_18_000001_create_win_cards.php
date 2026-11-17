<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** RF1 (specs/transactions/32): "Just sold" and "Just closed" win cards, shared only with the client's agreement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('tx_win_cards', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('deal_id')->unique();
            $table->unsignedBigInteger('agent_user_id');
            $table->string('headline', 120);
            $table->string('message', 400);
            $table->boolean('show_price')->default(false);
            $table->string('status', 8)->default('live'); // live, hidden
            $table->unsignedBigInteger('consent_by');
            $table->dateTime('consent_at');
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('tx_win_cards');
    }
};
