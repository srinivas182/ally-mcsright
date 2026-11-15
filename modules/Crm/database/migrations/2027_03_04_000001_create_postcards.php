<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Direct mail (specs/connect/18, C7c): postcard runs printed and mailed through Lob on RightAlly's account, paid
 * with RightAlly Credits; each card's Lob id, so a run can be cancelled before it prints.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('crm_postcard_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 12); // just_listed | just_sold | open_house | farming
            $table->string('size', 5)->default('4x6'); // 4x6 | 6x9
            $table->string('smart_list', 30);
            $table->char('listing_ulid', 26)->nullable();
            $table->string('headline', 60);
            $table->string('message', 400);
            $table->string('link', 500);
            $table->json('from'); // return address
            $table->string('status', 10)->default('draft'); // draft | scheduled | cancelled | failed
            $table->unsignedInteger('cards')->default(0);
            $table->unsignedInteger('undeliverable')->default(0);
            $table->unsignedInteger('cents_per_card')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->unsignedInteger('credits_refunded')->default(0);
            $table->date('send_date')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'id']);
        });
        $cell->create('crm_postcard_items', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('lob_id', 40)->nullable();
            $table->string('status', 14); // sent | undeliverable | cancelled | failed
            $table->dateTime('created_at');
            $table->unique(['run_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_postcard_items');
        $cell->dropIfExists('crm_postcard_runs');
    }
};
