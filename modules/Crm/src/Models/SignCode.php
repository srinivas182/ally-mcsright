<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\Concerns\HasPublicUlid;

/**
 * A text-for-info sign code: "Text OAK12 to (512) 555-0100" (specs/grow/05).
 *
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property int $user_id
 * @property string $property
 * @property string $details
 * @property bool $active
 * @property int $texts
 */
final class SignCode extends Model
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'crm_sign_codes';

    protected $fillable = ['code', 'user_id', 'property', 'details', 'active', 'texts'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
