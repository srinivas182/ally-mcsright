<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Lead Generation Engine 1b (specs/grow/03): the monthly home-value report a homeowner gets after a home-value funnel
 * (an updated price range, how it moved, and homes for sale nearby), with a one-click stop. Funnel ads (a funnel as
 * the ad's destination instead of a listing) need the listing on a campaign to be optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('site_home_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('site_funnel_id')->nullable();
            $table->unsignedBigInteger('user_id'); // the agent the report comes from
            $table->string('address', 150);
            $table->char('zip', 5);
            $table->unsignedTinyInteger('beds')->nullable();
            $table->unsignedInteger('sqft')->nullable();
            $table->unsignedBigInteger('last_mid')->nullable(); // dollars
            $table->date('next_on');
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->dateTime('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'contact_id', 'address'], 'site_home_reports_home_idx');
            $table->index(['tenant_id', 'unsubscribed_at', 'next_on'], 'site_home_reports_due_idx');
        });
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->char('listing_ulid', 26)->nullable()->change();
            $table->unsignedBigInteger('site_funnel_id')->nullable()->after('listing_label');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->dropColumn('site_funnel_id');
        });
        Schema::connection(Connections::cell())->dropIfExists('site_home_reports');
    }
};
