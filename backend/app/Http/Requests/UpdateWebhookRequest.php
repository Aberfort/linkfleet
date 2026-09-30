<?php

namespace App\Http\Requests;

use App\Enums\WebhookEvent;
use Illuminate\Validation\Rule;

class UpdateWebhookRequest extends StoreWebhookRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('webhook'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'required', 'string', 'max:2048', $this->reachableUrl()],
            'events' => ['sometimes', 'required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvent::subscribable())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
