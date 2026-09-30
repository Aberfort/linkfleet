<?php

namespace App\Http\Requests;

use App\Support\ConversionInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversionRequest extends FormRequest
{
    /** Who may report is decided per click, in the controller: only it knows which link is meant. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ConversionInput::normalise($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ConversionInput::rules();
    }

    public function messages(): array
    {
        return ConversionInput::messages();
    }
}
