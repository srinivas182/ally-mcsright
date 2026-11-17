<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK1 (specs/crm/20): booking links, video messages, and texting (A2P 10DLC) registration. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('outreach_booking_links', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('slug', 80);
            $table->string('title', 120);
            $table->string('intro', 500)->nullable();
            $table->string('kind', 20)->default('call');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('bookings')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'slug']);
        });
        $cell->create('outreach_videos', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('title', 120);
            $table->string('message', 500)->nullable();
            $table->string('path', 300);
            $table->string('mime', 40);
            $table->unsignedBigInteger('size');
            $table->string('token', 48)->unique();
            $table->unsignedInteger('views')->default(0);
            $table->dateTime('first_viewed_at')->nullable();
            $table->dateTime('last_viewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });
        Schema::connection(Connections::control())->create('outreach_texting_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('status', 20)->default('draft'); // draft, submitted, approved, rejected
            $table->text('brand');      // encrypted JSON (includes the EIN)
            $table->text('campaign');   // JSON
            $table->string('note', 500)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('outreach_texting_registrations');
        Schema::connection(Connections::cell())->dropIfExists('outreach_videos');
        Schema::connection(Connections::cell())->dropIfExists('outreach_booking_links');
    }
};
