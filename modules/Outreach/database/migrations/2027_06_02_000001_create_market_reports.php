<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK3 (specs/crm/21, D09): monthly market report emails for an area, from the agent, with a one-click stop. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('outreach_market_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('user_id'); // the agent it comes from
            $table->string('place', 80); // "austin-tx" or "78704"
            $table->date('next_on');
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->dateTime('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'contact_id'], 'outreach_market_reports_contact_idx'); // one area per person
            $table->index(['tenant_id', 'unsubscribed_at', 'next_on'], 'outreach_market_reports_due_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('outreach_market_reports');
    }
};
