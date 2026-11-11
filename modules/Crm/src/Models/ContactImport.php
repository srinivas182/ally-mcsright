<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $file_path
 * @property string $original_name
 * @property string $status
 * @property list<string> $headers
 * @property array<string, string>|null $mapping
 * @property array<string, mixed>|null $options
 * @property int $rows
 * @property int $created_count
 * @property int $duplicates
 * @property int $error_count
 * @property list<array{row: int, reason: string}>|null $errors
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class ContactImport extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_imports';

    protected $fillable = ['user_id', 'file_path', 'original_name', 'status', 'headers', 'mapping', 'options', 'rows', 'created_count', 'duplicates', 'error_count', 'errors', 'started_at', 'finished_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'errors' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
