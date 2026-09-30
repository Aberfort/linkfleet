<?php

namespace App\Http\Requests;

use App\Models\Site;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = Workspace::find($this->integer('workspace_id'));

        // A workspace the caller cannot see is answered by validation ("no
        // such workspace"), exactly like an id that does not exist - a 403
        // here would confirm that it does.
        if (! $workspace || $this->user()->roleIn($workspace) === null) {
            return true;
        }

        return $this->user()->can('create', [Site::class, $workspace]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => [
                'required', 'integer',
                Rule::in(array_keys($this->user()->workspaceRoles())),
            ],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('sites')->where('workspace_id', $this->input('workspace_id')),
            ],
            'domain' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'workspace_id.in' => 'Такого workspace не існує.',
        ];
    }
}
