<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * DocIQ reading (specs/ai/03, Sprint 15A): what each uploaded document says (text per page, read directly or by
 * OCR on our own servers), what kind of document it is, the facts it states (each tied to a quote on a page),
 * documents it makes necessary, and sensitive numbers it contains. Text and facts are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('tx_document_readings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('document_id')->unique()->constrained('tx_documents')->cascadeOnDelete();
            $table->string('status', 12)->default('queued'); // queued, read, failed, unsupported
            $table->unsignedSmallInteger('pages')->default(0);
            $table->longText('page_text')->nullable(); // encrypted JSON: list of page texts
            $table->json('methods')->nullable(); // per page: text, ocr, blank
            $table->string('doc_type', 40)->nullable();
            $table->string('title', 160)->nullable();
            $table->json('segments')->nullable(); // [{type, title, from, to}]
            $table->text('facts')->nullable(); // encrypted JSON: [{name, value, page, quote}]
            $table->json('sensitive')->nullable(); // {ssn, bank, dob, wire}
            $table->string('error', 300)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });

        $schema->table('tx_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_document_id')->nullable(); // set on a document split out of a larger upload
            $table->unsignedSmallInteger('pages_from')->nullable();
            $table->unsignedSmallInteger('pages_to')->nullable();
            $table->unsignedSmallInteger('split_into')->nullable(); // on the original: how many documents it was split into
        });

        $schema->table('tx_items', function (Blueprint $table): void {
            $table->string('added_reason', 300)->nullable(); // set when DocIQ added the item because a document requires it
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_items', fn (Blueprint $t) => $t->dropColumn('added_reason'));
        $schema->table('tx_documents', fn (Blueprint $t) => $t->dropColumn(['parent_document_id', 'pages_from', 'pages_to', 'split_into']));
        $schema->dropIfExists('tx_document_readings');
    }
};
