<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Revenue share attribution: the sponsor chosen when inviting (specs/compensation/02, addition 12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('invitations', fn (Blueprint $t) => $t->unsignedBigInteger('sponsor_user_id')->nullable());
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('invitations', fn (Blueprint $t) => $t->dropColumn('sponsor_user_id'));
    }
};
