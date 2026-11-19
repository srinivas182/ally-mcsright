<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK4: a brokerage's own ideas and words for its AI blog writer, and the website post each draft became. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $c = Schema::connection(Connections::control());
        $c->table('content_desks', function (Blueprint $t): void {
            $t->json('inputs')->nullable(); // {ideas: [..], words: "..."}
        });
        $c->table('content_drafts', function (Blueprint $t): void {
            $t->string('ref', 40)->nullable(); // the website post's ulid (MK4)
        });
    }

    public function down(): void
    {
        $c = Schema::connection(Connections::control());
        $c->table('content_desks', fn (Blueprint $t) => $t->dropColumn('inputs'));
        $c->table('content_drafts', fn (Blueprint $t) => $t->dropColumn('ref'));
    }
};
