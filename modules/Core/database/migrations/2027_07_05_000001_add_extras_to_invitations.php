<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AG6: what other modules set for the person being invited (e.g. the commission plan an agent starts on). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('invitations', fn (Blueprint $t) => $t->json('extras')->nullable());
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('invitations', fn (Blueprint $t) => $t->dropColumn('extras'));
    }
};
