<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * One indexed chunk of a tenant record, with its access metadata and embedding.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $source_type
 * @property string $source_id
 * @property int $chunk_index
 * @property int|null $owner_user_id
 * @property list<int> $team_ids
 * @property int|null $office_id
 * @property bool $sensitive
 * @property string $model_id
 * @property int $dimensions
 * @property string $embedding packed float32
 * @property string $content
 */
final class VectorDocument extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'ai_vector_documents';

    protected $fillable = [
        'source_type', 'source_id', 'chunk_index', 'owner_user_id', 'team_ids', 'office_id',
        'sensitive', 'model_id', 'dimensions', 'embedding', 'content',
    ];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['team_ids' => 'array', 'sensitive' => 'boolean', 'dimensions' => 'integer', 'owner_user_id' => 'integer', 'office_id' => 'integer'];
    }

    /**
     * @param  list<float>  $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public function vector(): array
    {
        $values = unpack('g*', $this->embedding);

        return $values === false ? [] : array_values(array_map('floatval', $values));
    }
}
