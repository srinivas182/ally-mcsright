<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * IDX12: the fields in each board's own agreement form (found in the PDF, matched to the brokerage's details, checked
 * by HQ), and what the brokerage filled in before signing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('idx_boards', function (Blueprint $table): void {
            $table->json('agreement_fields')->nullable(); // [{key, label, type, page, x, y, w, h, source, role, required}]
            $table->timestamp('agreement_fields_at')->nullable(); // when HQ last saved them
        });
        Schema::connection(Connections::control())->table('idx_subscriptions', function (Blueprint $table): void {
            $table->json('form_values')->nullable(); // key => value, reviewed by the brokerage
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('idx_boards', fn (Blueprint $table) => $table->dropColumn(['agreement_fields', 'agreement_fields_at']));
        Schema::connection(Connections::control())->table('idx_subscriptions', fn (Blueprint $table) => $table->dropColumn('form_values'));
    }
};
