<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** IDX8: "My board isn't listed": a brokerage asks HQ to add an MLS board; it is emailed when the board opens. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('idx_board_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120);
            $table->char('state', 2);
            $table->string('website', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('email', 190); // who asked, for the "it's open" email
            $table->string('status', 12)->default('open'); // open | added | closed
            $table->unsignedBigInteger('board_id')->nullable()->index();
            $table->string('hq_note', 500)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('idx_board_requests');
    }
};
