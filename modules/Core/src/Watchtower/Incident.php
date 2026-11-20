<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Support\Connections;

/**
 * One problem on RightAlly (OB1): every occurrence with the same fingerprint counts here.
 *
 * @property string $id
 * @property string $fingerprint
 * @property string $kind
 * @property string $severity
 * @property string $status
 * @property string $title
 * @property string|null $exception_class
 * @property string|null $message
 * @property string|null $file
 * @property int|null $line
 * @property string|null $location
 * @property string|null $surface
 * @property string|null $trace
 * @property array<string, mixed>|null $context
 * @property int $occurrences
 * @property int $brokerages
 * @property Carbon|null $hour_bucket
 * @property int $hour_count
 * @property string|null $first_release
 * @property string|null $last_release
 * @property bool $ongoing
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $alerted_at
 * @property string|null $alerted_severity
 * @property int $alerted_occurrences
 * @property Carbon|null $regressed_at
 * @property string|null $fixed_in
 * @property Carbon|null $fixed_at
 * @property Carbon|null $resolved_at
 * @property int|null $updated_by
 * @property array<string, mixed>|null $analysis
 * @property Carbon|null $analysed_on
 */
final class Incident extends Model
{
    use HasUlids;

    public const KINDS = ['exception' => 'Server error', 'browser' => 'Browser error', 'job' => 'Failed job', 'scheduler' => 'Scheduled task',
        'queue' => 'Queue backlog', 'check' => 'System check', 'downtime' => 'Downtime', 'slow' => 'Slow page'];

    public const SEVERITIES = ['critical' => 'Critical', 'high' => 'High', 'normal' => 'Normal'];

    public const STATUSES = ['new' => 'New', 'acknowledged' => 'Acknowledged', 'fixed' => 'Fixed', 'resolved' => 'Resolved', 'ignored' => 'Ignored'];

    /** Still needs someone. */
    public const OPEN = ['new', 'acknowledged'];

    protected $table = 'watchtower_incidents';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return Connections::control() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'analysis' => 'array', 'ongoing' => 'boolean', 'hour_bucket' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime',
            'alerted_at' => 'datetime', 'regressed_at' => 'datetime', 'fixed_at' => 'datetime', 'resolved_at' => 'datetime', 'analysed_on' => 'date', 'occurrences' => 'integer',
            'brokerages' => 'integer', 'hour_count' => 'integer', 'alerted_occurrences' => 'integer', 'line' => 'integer', 'updated_by' => 'integer'];
    }

    public static function rank(?string $severity): int
    {
        return match ($severity) {
            'critical' => 3, 'high' => 2, 'normal' => 1, default => 0,
        };
    }

    /** A short reference people can quote: "WT-7KQ2M4". */
    public function reference(): string
    {
        return 'WT-'.strtoupper(substr($this->id, -6));
    }

    /** Occurrences in the current hour (the bucket may be from an earlier hour). */
    public function lastHour(): int
    {
        return $this->hour_bucket !== null && $this->hour_bucket->greaterThanOrEqualTo(now()->startOfHour()) ? $this->hour_count : 0;
    }
}
