<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AP1 (specs/listings/05): guided listing appointments and buyer consultations, with the buyer agreement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('appt_consultations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('kind', 8); // listing, buyer
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 12)->default('in_progress'); // in_progress, done
            $table->json('answers');
            $table->string('agreement_status', 10)->default('none'); // none, signed
            $table->date('agreement_signed_on')->nullable();
            $table->date('agreement_ends_on')->nullable();
            $table->string('agreement_file', 300)->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->dateTime('guide_sent_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'contact_id', 'kind']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('appt_consultations');
    }
};
