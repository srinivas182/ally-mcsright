<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests\Hq;

use Illuminate\Foundation\Http\FormRequest;

final class TenantReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }
}
