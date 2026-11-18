<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** SU2 (specs/setup/02): the owner's answers to the guided setup, and what each answer set up. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('setup_guide', function (Blueprint $table): void {
            $table->tenant();
            $table->json('answers')->nullable();
            $table->json('applied')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->primary('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('setup_guide');
    }
};
