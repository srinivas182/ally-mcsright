<?php

declare(strict_types=1);

namespace Modules\Transactions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\BindsForViewer;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;
use Modules\Transactions\DealAccess;

/**
 * @property int $id
 * @property string $ulid
 * @property int $deal_id
 * @property int|null $item_id
 * @property string $filename
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int $version
 * @property string $via
 */
final class Document extends Model
{
    use BelongsToTenant;
    use BindsForViewer;
    use HasPublicUlid;

    protected $table = 'tx_documents';

    protected $fillable = ['deal_id', 'item_id', 'filename', 'path', 'mime', 'size', 'sha256', 'version', 'via', 'uploaded_by'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /** SX1: a document is seen with its deal. */
    public static function viewable(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->getModel()->qualifyColumn('deal_id'), DealAccess::query($user)->select('id'));
    }
}
