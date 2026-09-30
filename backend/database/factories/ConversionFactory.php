<?php

namespace Database\Factories;

use App\Models\Click;
use App\Models\Conversion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversion>
 */
class ConversionFactory extends Factory
{
    public function definition(): array
    {
        return [
            // link_id is filled from the click in configure(), so the two
            // can never disagree.
            'click_id' => Click::factory(),
            'event' => 'signup',
            'external_id' => '',
            'source' => Conversion::SOURCE_SERVER,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Conversion $conversion) {
            $conversion->link_id ??= Click::find($conversion->click_id)?->link_id;
        });
    }

    public function worth(string $value, string $currency = 'USD'): static
    {
        return $this->state(fn () => ['value' => $value, 'currency' => $currency]);
    }
}
