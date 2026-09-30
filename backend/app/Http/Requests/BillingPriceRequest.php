<?php

namespace App\Http\Requests;

use App\Billing\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A price the customer picked. Only prices from config/billing.php are
 * accepted: the id travels through the browser, so it is never trusted to
 * name something we do not sell.
 */
class BillingPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBilling', $this->route('workspace'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prices = app(PlanCatalog::class)->forSale()->flatMap(fn ($plan) => $plan->priceIds())->all();

        return [
            'price_id' => ['required', 'string', Rule::in($prices)],
        ];
    }

    public function messages(): array
    {
        return [
            'price_id.in' => 'Невідомий тарифний план.',
        ];
    }
}
