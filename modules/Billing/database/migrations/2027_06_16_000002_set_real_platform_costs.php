<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/**
 * PB4, control database: replaces the two placeholder costs with planning figures, and adds the MLS data fee.
 * Only rows still at their placeholder are changed: a figure HQ has already entered is kept.
 *
 * - Platform per person, $4 a month: servers, databases, cache, file storage and backups about $1.25; monitoring, logs
 *   and search about $0.25; support team about $2.00; security and compliance (pen test, SOC 2) about $0.50.
 * - Hosting per brokerage, $10 a month: listing sync and search index, website domain and certificate, backups.
 * - MLS data fee per brokerage, $10 a month: varies by MLS; check each MLS's vendor fee sheet.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $db = DB::connection(Connections::control())->table('cost_catalog');
        $now = now();
        $db->clone()->where('key', 'platform.seat')->where('cost_microcents', 200_000_000)->where('note', 'like', 'Placeholder%')->update([
            'cost_microcents' => 400_000_000, 'updated_at' => $now,
            'note' => 'Planning figure: servers, databases and backups $1.25; monitoring and search $0.25; support $2.00; security and compliance $0.50.']);
        $db->clone()->where('key', 'hosting.brokerage')->where('cost_microcents', 0)->update([
            'cost_microcents' => 1_000_000_000, 'updated_at' => $now,
            'note' => 'Planning figure: listing sync and search index, website domain and certificate, backups.']);
        $db->clone()->insertOrIgnore(['key' => 'mls.data_fee', 'label' => 'MLS data fee', 'unit' => 'brokerage per month', 'kind' => 'monthly',
            'cost_microcents' => 1_000_000_000, 'free_units_monthly' => 0, 'source_url' => null,
            'note' => 'Planning figure: varies by MLS. Check each MLS\'s vendor fee sheet and enter the real fee.', 'checked_at' => null,
            'updated_by' => null, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $db = DB::connection(Connections::control())->table('cost_catalog');
        $db->clone()->where('key', 'mls.data_fee')->delete();
        $db->clone()->where('key', 'platform.seat')->where('cost_microcents', 400_000_000)->update(['cost_microcents' => 200_000_000, 'note' => 'Placeholder: enter your real cost per person per month.']);
        $db->clone()->where('key', 'hosting.brokerage')->where('cost_microcents', 1_000_000_000)->update(['cost_microcents' => 0]);
    }
};
