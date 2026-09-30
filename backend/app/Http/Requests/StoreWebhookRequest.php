<?php

namespace App\Http\Requests;

use App\Enums\WebhookEvent;
use App\Models\Webhook;
use App\Support\OutboundUrlGuard;
use App\Support\UnsafeOutboundUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Webhook::class, $this->route('workspace')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', $this->reachableUrl()],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvent::subscribable())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'events.required' => 'Оберіть хоча б одну подію.',
            'events.min' => 'Оберіть хоча б одну подію.',
            'events.*.in' => 'Невідома подія.',
        ];
    }

    /**
     * Refuses an unsafe address up front, while the person is still looking
     * at the form. Delivery checks again - DNS can change - so this is
     * about the message, not the protection.
     */
    protected function reachableUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            try {
                app(OutboundUrlGuard::class)->resolve((string) $value);
            } catch (UnsafeOutboundUrl $e) {
                $fail($e->getMessage());
            }
        };
    }
}
