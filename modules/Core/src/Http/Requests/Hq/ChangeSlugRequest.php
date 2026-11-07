<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests\Hq;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeSlugRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['slug' => ['required', 'string', 'max:40']];
    }
}
