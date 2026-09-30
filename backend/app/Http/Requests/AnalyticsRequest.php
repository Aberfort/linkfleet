<?php

namespace App\Http\Requests;

use App\Support\AnalyticsRange;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The window and options for an analytics or export request. Authorisation
 * is left to the controller, which knows which site or link is meant.
 */
class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A shortcut for "the last N days ending today", resolved on the
            // server: a browser's idea of today can be a day ahead of ours.
            'days' => ['nullable', 'integer', 'between:1,'.AnalyticsRange::MAX_DAYS, 'prohibits:from,to'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from', $this->withinMaxSpan()],
            'compare' => ['nullable', Rule::in(['previous'])],
        ];
    }

    public function messages(): array
    {
        return [
            'from.date_format' => 'Дата має бути у форматі РРРР-ММ-ДД.',
            'to.date_format' => 'Дата має бути у форматі РРРР-ММ-ДД.',
            'to.before_or_equal' => 'Кінець періоду не може бути в майбутньому.',
            'days.between' => 'Кількість днів — від 1 до '.AnalyticsRange::MAX_DAYS.'.',
            'days.prohibits' => 'Вкажіть або days, або from і to.',
            'to.after_or_equal' => 'Кінець періоду не може бути раніше за початок.',
        ];
    }

    public function range(): AnalyticsRange
    {
        if ($this->validated('days')) {
            return AnalyticsRange::lastDays((int) $this->validated('days'));
        }

        return AnalyticsRange::make($this->validated('from'), $this->validated('to'));
    }

    public function wantsComparison(): bool
    {
        return $this->validated('compare') === 'previous';
    }

    private function withinMaxSpan(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $from = $this->input('from');

            if (! $from || ! is_string($value)) {
                return;
            }

            $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($value)) + 1;

            if ($days > AnalyticsRange::MAX_DAYS) {
                $fail('Період не може бути довшим за '.AnalyticsRange::MAX_DAYS.' днів.');
            }
        };
    }
}
