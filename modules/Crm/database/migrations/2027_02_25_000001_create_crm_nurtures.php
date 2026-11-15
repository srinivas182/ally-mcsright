<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Lead-IQ long nurture (specs/grow/09): a lead nobody is talking to gets 12 months of AI follow-up, timed by what
 * they do (faster when they come back to the website or homes come up for their search, slower when they don't
 * answer), and handed straight to the agent the moment they reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('crm_nurtures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('status', 12)->default('active'); // active | replied | stopped | done
            $table->string('stopped_reason', 30)->nullable();
            $table->unsignedSmallInteger('touches')->default(0);
            $table->unsignedSmallInteger('unanswered')->default(0); // touches since they last wrote
            $table->dateTime('next_at');
            $table->dateTime('last_touch_at')->nullable();
            $table->string('last_reason', 30)->nullable(); // new_listings | price_drop | home_value | market | check_in
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'status', 'next_at']);
        });
        $cell->table('crm_ai_responder_settings', function (Blueprint $table): void {
            $table->boolean('nurture_enabled')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_ai_responder_settings', function (Blueprint $table): void {
            $table->dropColumn('nurture_enabled');
        });
        $cell->dropIfExists('crm_nurtures');
    }
};
