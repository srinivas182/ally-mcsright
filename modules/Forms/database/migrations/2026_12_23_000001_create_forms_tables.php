<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Forms part 1 (specs/forms/01): templates, clauses, packets of forms on a deal, and signer roles. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('form_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 160);
            $table->string('source', 12)->default('brokerage'); // brokerage, association
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('path', 300);
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('pages');
            $table->json('page_sizes'); // [[width, height], ...] in points
            $table->json('fields');     // [{key, type, page, x, y, w, h, role, bind, required, label}]
            $table->string('checklist_label', 160)->nullable(); // files the signed copy to this checklist item
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        $schema->create('form_clauses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('title', 120);
            $table->text('body');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        $schema->create('form_packets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('deal_id')->index();
            $table->string('title', 160);
            $table->string('status', 10); // draft, sent, completed, voided
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        $schema->create('form_packet_forms', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('packet_id')->constrained('form_packets')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('form_templates');
            $table->unsignedSmallInteger('template_version');
            $table->json('values'); // field key => value (non-signature fields)
            $table->unsignedSmallInteger('sort');
        });

        $schema->create('form_signers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('packet_id')->constrained('form_packets')->cascadeOnDelete();
            $table->string('role', 30); // buyer1, seller1, buyer_agent, listing_agent, broker...
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('phone', 30)->nullable();
            $table->unsignedSmallInteger('sign_order')->default(1);
            $table->string('status', 10)->default('waiting'); // waiting, sent, viewed, signed, declined
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['form_signers', 'form_packet_forms', 'form_packets', 'form_clauses', 'form_templates'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
