<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SX2, control database: what browsers reported the content security policy blocked (or would block), counted per
 * surface, rule, blocked origin and page path. No personal details: origins and paths only.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection(Connections::control())->create('csp_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('surface', 20); // back-office, hq, public
            $table->string('directive', 60);
            $table->string('blocked', 200);
            $table->string('page', 200);
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unique(['surface', 'directive', 'blocked', 'page']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('csp_reports');
    }
};
