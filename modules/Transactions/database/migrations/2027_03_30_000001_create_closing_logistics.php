<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ DQ5b (specs/ai/02, §21): the brokerage's preferred vendors, appointments with confirm and reschedule links
 * for vendors, the moving checklist in the client portal, and closing time and place.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->create('tx_vendors', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('category', 20); // inspector, appraiser, surveyor, mover, utilities, internet, insurance, cleaner, locksmith, other
            $table->string('name', 160);
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('note', 300)->nullable(); // e.g. "10% off for our clients"
            $table->boolean('show_clients')->default(true); // listed in the clients' moving checklist
            $table->timestamps();
        });
        $schema->create('tx_appointments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('kind', 14); // inspection, appraisal, survey, walkthrough, other
            $table->string('title', 120);
            $table->string('vendor_name', 160)->nullable();
            $table->string('vendor_email', 254)->nullable();
            $table->string('vendor_phone', 30)->nullable();
            $table->dateTime('starts_at');
            $table->string('status', 12); // requested, confirmed, reschedule, done, missed, cancelled
            $table->dateTime('proposed_at')->nullable(); // the vendor's suggested new time
            $table->string('vendor_note', 500)->nullable();
            $table->string('token_hash', 64)->nullable()->unique(); // the vendor's confirm/reschedule link
            $table->dateTime('reminded_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        $schema->create('tx_portal_moving', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('access_id')->constrained('tx_portal_access')->cascadeOnDelete();
            $table->string('item', 30);
            $table->dateTime('done_at');
            $table->unique(['access_id', 'item']);
        });
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->string('closing_time', 5)->nullable(); // 14:00
            $table->string('closing_place', 300)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_deals', fn (Blueprint $table) => $table->dropColumn(['closing_time', 'closing_place']));
        $schema->dropIfExists('tx_portal_moving');
        $schema->dropIfExists('tx_appointments');
        $schema->dropIfExists('tx_vendors');
    }
};
