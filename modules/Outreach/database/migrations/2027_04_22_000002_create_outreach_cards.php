<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK2 (specs/crm/21): handwritten card requests, queued for HQ's handwriting partner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('outreach_card_requests', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('occasion', 30);
            $table->string('message', 400);
            $table->string('to_name', 160);
            $table->string('to_line1', 200);
            $table->string('to_line2', 200)->nullable();
            $table->string('to_city', 80);
            $table->string('to_state', 2);
            $table->string('to_zip', 10);
            $table->string('from_name', 160);
            $table->string('from_company', 160);
            $table->string('status', 12)->default('queued'); // queued, sent
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('outreach_card_requests');
    }
};
