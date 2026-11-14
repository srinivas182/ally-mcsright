<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Sprint 17C-3b: agent websites (and their own domains) and landing pages (specs/websites/09). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('site_pages', function (Blueprint $table): void {
            $table->string('layout', 10)->default('standard')->after('path'); // standard | landing
        });
        // A custom domain can belong to one agent's website instead of the brokerage's.
        Schema::connection(Connections::control())->table('tenant_domains', function (Blueprint $table): void {
            $table->char('site_ulid', 26)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('tenant_domains', fn (Blueprint $table) => $table->dropColumn('site_ulid'));
        Schema::connection(Connections::cell())->table('site_pages', fn (Blueprint $table) => $table->dropColumn('layout'));
    }
};
