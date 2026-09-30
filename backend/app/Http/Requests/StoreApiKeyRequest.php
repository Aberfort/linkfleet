<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-api-keys');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'access' => ['required', Rule::in(['read', 'write'])],
            // Null means "every workspace I belong to, now and later".
            'workspace_id' => ['nullable', 'integer', Rule::in(array_keys($this->user()->workspaceRoles()))],
        ];
    }

    public function messages(): array
    {
        return [
            'workspace_id.in' => 'Такого workspace не існує.',
        ];
    }
}
