<?php

declare(strict_types=1);

namespace Modules\Listings\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;

/**
 * An MLS's display rules (specs/websites/02): attribution, disclaimer, logo, sold data, suppressed fields,
 * freshness and indexing.
 *
 * @property int $id
 * @property string $name
 * @property string $program
 * @property string $attribution_template
 * @property string|null $disclaimer
 * @property string|null $logo_url
 * @property bool $sold_allowed
 * @property int $sold_delay_days
 * @property list<string>|null $suppressed_fields
 * @property int $freshness_minutes
 * @property bool $index_off_market
 */
final class RulesProfile extends Model
{
    protected $table = 'mls_rules_profiles';

    protected $fillable = ['name', 'program', 'attribution_template', 'disclaimer', 'logo_url', 'sold_allowed', 'sold_delay_days', 'suppressed_fields', 'freshness_minutes', 'index_off_market'];

    public function getConnectionName(): ?string
    {
        return Connections::listings() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sold_allowed' => 'boolean', 'suppressed_fields' => 'array', 'index_off_market' => 'boolean'];
    }
}
