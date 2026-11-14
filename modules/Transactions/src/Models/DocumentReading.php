<?php

declare(strict_types=1);

namespace Modules\Transactions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * What DocIQ read from one document (specs/ai/03). Page text and facts are encrypted at rest.
 *
 * @property int $id
 * @property int $document_id
 * @property string $status
 * @property int $pages
 * @property list<string>|null $page_text
 * @property list<string>|null $methods
 * @property string|null $doc_type
 * @property string|null $title
 * @property list<array{type: ?string, title: string, from: int, to: int}>|null $segments
 * @property list<array{name: string, value: string, page: int, quote: string}>|null $facts
 * @property array{ssn: int, bank: int, dob: int, wire: bool}|null $sensitive
 * @property string|null $error
 * @property CarbonImmutable|null $read_at
 */
final class DocumentReading extends Model
{
    use BelongsToTenant;

    protected $table = 'tx_document_readings';

    protected $fillable = ['document_id', 'status', 'pages', 'page_text', 'methods', 'doc_type', 'title', 'segments', 'facts', 'sensitive', 'error', 'read_at'];

    public function getConnectionName(): ?string
    {
        return Connections::cell() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['page_text' => 'encrypted:array', 'methods' => 'array', 'segments' => 'array', 'facts' => 'encrypted:array', 'sensitive' => 'array',
            'read_at' => 'immutable_datetime', 'pages' => 'integer'];
    }
}
