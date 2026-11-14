<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Forms part 5c (specs/forms/01): association form libraries (connected per brokerage, member-verified),
 * edition tracking with new-version alerts, and templates imported from other systems. A new edition is always
 * a NEW template; the old one is retired, never overwritten, so packets already out keep their exact form.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('form_libraries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('connector', 30);
            $table->string('name', 120);
            $table->string('member_id', 20)->nullable(); // NRDS or association member number
            $table->string('member_name', 120)->nullable();
            $table->dateTime('member_verified_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'connector']);
        });

        $schema->create('form_library_forms', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('library_id')->constrained('form_libraries')->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->string('name', 160);
            $table->string('category', 60)->nullable();
            $table->string('edition', 30); // the edition in the library's catalog
            $table->unsignedBigInteger('template_id')->nullable(); // the brokerage's current template, once added
            $table->string('template_edition', 30)->nullable(); // the edition that template is
            $table->dateTime('update_noticed_at')->nullable(); // when we told the brokerage a new edition exists
            $table->timestamps();
            $table->unique(['library_id', 'external_id']);
        });

        $schema->table('form_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('library_form_id')->nullable();
            $table->string('edition', 30)->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable(); // the newer edition's template
            $table->string('import_source', 20)->nullable(); // dotloop, skyslope, zipform, association, pdf, library
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('form_templates', fn (Blueprint $t) => $t->dropColumn(['library_form_id', 'edition', 'superseded_by', 'import_source']));
        $schema->dropIfExists('form_library_forms');
        $schema->dropIfExists('form_libraries');
    }
};
