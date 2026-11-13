<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Forms part 4 (specs/forms/01): counter-offers and addenda linked in one negotiation history, and changes
 * made after sending that already-signed signers initial (re-sign only what changed).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->table('form_packets', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_packet_id')->nullable();
            $table->char('negotiation_ulid', 26)->nullable()->index(); // the first packet of the negotiation
            $table->unsignedSmallInteger('round')->default(1);
            $table->string('kind', 10)->default('original'); // original, counter, addendum
            $table->json('change_summary')->nullable(); // [{form, label, old, new}] against the parent
        });

        $schema->create('form_changes', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('packet_id')->constrained('form_packets')->cascadeOnDelete();
            $table->unsignedBigInteger('form_id');
            $table->string('field_key', 60);
            $table->string('label', 160);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('reason', 300);
            $table->unsignedBigInteger('changed_by');
            $table->dateTime('changed_at');
        });

        // One row per signer who had already signed when the change was made; initialled when "at" is set.
        $schema->create('form_change_acks', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('change_id')->constrained('form_changes')->cascadeOnDelete();
            $table->unsignedBigInteger('signer_id')->index();
            $table->string('initials', 12)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('at')->nullable();
            $table->primary(['change_id', 'signer_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('form_change_acks');
        $schema->dropIfExists('form_changes');
        $schema->table('form_packets', fn (Blueprint $t) => $t->dropIndex(['negotiation_ulid']));
        $schema->table('form_packets', fn (Blueprint $t) => $t->dropColumn(['parent_packet_id', 'negotiation_ulid', 'round', 'kind', 'change_summary']));
    }
};
