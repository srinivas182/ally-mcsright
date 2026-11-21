<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** IDX9: the brokerage's own words for an area page (unique local content), or the page switched off. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('site_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('slug', 140);
            $table->text('intro')->nullable();
            $table->boolean('hidden')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('site_areas');
    }
};
