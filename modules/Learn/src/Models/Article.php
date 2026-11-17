<?php

declare(strict_types=1);

namespace Modules\Learn\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A page in the brokerage's knowledge base (LN1): how we do things here, vendors, scripts, office details.
 *
 * @property int $id
 * @property string $ulid
 * @property string $title
 * @property string $category
 * @property string $body
 * @property bool $required
 * @property int $version
 * @property string $status
 * @property int|null $updated_by
 * @property CarbonImmutable $updated_at
 */
final class Article extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'learn_articles';

    protected $fillable = ['title', 'category', 'body', 'required', 'version', 'status', 'updated_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'version' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
