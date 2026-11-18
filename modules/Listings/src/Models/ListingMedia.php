<?php

declare(strict_types=1);

namespace Modules\Listings\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;

/**
 * A listing photo: the MLS's original address and our stored WebP copies.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $media_key
 * @property int $position
 * @property string $source_url
 * @property string|null $storage_path
 * @property int|null $width
 * @property int|null $height
 * @property string|null $caption
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $first_attempt_at
 * @property string|null $error
 * @property CarbonImmutable|null $modification_timestamp
 * @property string|null $room
 * @property string|null $tags JSON list of PhotoTags::FEATURES keys
 * @property string|null $alt_text
 * @property CarbonImmutable|null $tagged_at
 */
final class ListingMedia extends Model
{
    protected $table = 'listing_media';

    protected $fillable = ['listing_id', 'media_key', 'position', 'source_url', 'storage_path', 'width', 'height', 'caption', 'status', 'attempts', 'first_attempt_at', 'error', 'modification_timestamp', 'room', 'tags', 'alt_text', 'tagged_at'];

    public function getConnectionName(): ?string
    {
        return Connections::listings() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['first_attempt_at' => 'immutable_datetime', 'modification_timestamp' => 'immutable_datetime', 'tagged_at' => 'immutable_datetime'];
    }
}
