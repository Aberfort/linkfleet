<?php

namespace App\Support;

/**
 * What a conversion report may contain, shared by the two ways of sending
 * one (the authenticated API and the browser pixel) so they cannot come to
 * disagree about what is valid.
 */
final class ConversionInput
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'click_id' => ['required', 'string', 'max:64'],
            // A name for what happened: signup, purchase, trial.started
            'event' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.:-]{0,63}$/'],
            // `decimal` also makes min/max compare the number, not its length.
            'value' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            // Money without a currency is not a number anyone can add up.
            'currency' => ['nullable', 'required_with:value', 'alpha:ascii', 'size:3'],
            'external_id' => ['nullable', 'string', 'max:128'],
        ];
    }

    public static function messages(): array
    {
        return [
            'event.regex' => 'Назва події: латинські літери, цифри та . _ : - (до 64 символів).',
            'value.decimal' => 'Сума — число не більше ніж із двома знаками після коми.',
            'currency.required_with' => 'Для суми потрібна валюта.',
            'currency.size' => 'Валюта — трилітерний код, наприклад USD.',
        ];
    }

    /**
     * Case and stray whitespace are not worth a rejection: "Purchase " is the
     * event "purchase". Done before validation so the rules stay strict.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        foreach (['click_id', 'event', 'currency', 'external_id'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (isset($input['event']) && is_string($input['event'])) {
            $input['event'] = strtolower($input['event']);
        }

        if (isset($input['currency']) && is_string($input['currency'])) {
            $input['currency'] = strtoupper($input['currency']);
        }

        foreach (['value', 'currency', 'external_id'] as $optional) {
            if (($input[$optional] ?? null) === '') {
                $input[$optional] = null;
            }
        }

        return $input;
    }
}
