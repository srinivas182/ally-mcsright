<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Agent roster part 1 (specs/roster/01): credentials, documents, guard settings and per-deal overrides. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('roster_credentials', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 12); // licence, eo, association, mls
            $table->char('state_code', 2)->nullable();
            $table->string('number', 40)->nullable();
            $table->string('issuer', 120)->nullable(); // association, MLS or insurer
            $table->unsignedBigInteger('coverage_cents')->nullable(); // E&O per-claim limit
            $table->date('expires_on')->nullable();
            $table->string('status', 10)->default('current'); // current, replaced
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verification_method', 10)->nullable(); // manual, arello
            $table->string('verification_note', 300)->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->unsignedSmallInteger('reminded_days')->nullable(); // smallest threshold already sent
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'kind', 'status'], 'roster_cred_user_idx');
            $table->index(['tenant_id', 'expires_on'], 'roster_cred_expiry_idx');
        });

        $schema->create('roster_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 20); // agreement, w9, licence_copy, eo_certificate, verification, other
            $table->string('filename', 200);
            $table->string('path', 300);
            $table->string('mime', 100);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });

        $schema->create('roster_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->boolean('guard_licence')->default(false); // on once the roster is loaded (owner's choice)
            $table->boolean('eo_required')->default(true);
            $table->timestamps();
            $table->primary('tenant_id');
        });

        $schema->create('roster_overrides', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('deal_id');
            $table->string('reason', 300);
            $table->unsignedBigInteger('by');
            $table->dateTime('created_at');
            $table->primary(['tenant_id', 'deal_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['roster_overrides', 'roster_settings', 'roster_documents', 'roster_credentials'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
