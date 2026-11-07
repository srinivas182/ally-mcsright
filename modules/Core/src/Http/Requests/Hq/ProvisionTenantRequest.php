<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests\Hq;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\Enums\TenantType;

final class ProvisionTenantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([TenantType::Brokerage->value, TenantType::Solo->value])],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:40'],
            'state_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'owner_name' => ['required', 'string', 'max:160'],
            'owner_email' => ['required', 'email:rfc', 'max:254'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
