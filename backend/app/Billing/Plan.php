<?php

namespace App\Billing;

/**
 * One row of config/billing.php. `rank` is its position in that file, which
 * is what makes "the better of two plans" a comparison of two integers.
 */
final class Plan
{
    /**
     * @param  array<string, int|null>  $limits  resource value => ceiling; null (or absent) is unlimited
     * @param  array<string, string>  $prices  interval (monthly, yearly) => Paddle price id
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $limits,
        public readonly array $prices,
        public readonly int $rank,
    ) {}

    public function limit(LimitedResource $resource): ?int
    {
        return $this->limits[$resource->value] ?? null;
    }

    /** @return array<int, string> */
    public function priceIds(): array
    {
        return array_values($this->prices);
    }

    public function isForSale(): bool
    {
        return $this->prices !== [];
    }

    /**
     * What the API says about a plan. `limits` always names every resource,
     * so a client never has to guess what a missing key means.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'limits' => $this->limitsArray(),
            'prices' => (object) $this->prices,
        ];
    }

    /** @return array<string, int|null> */
    public function limitsArray(): array
    {
        $limits = [];

        foreach (LimitedResource::cases() as $resource) {
            $limits[$resource->value] = $this->limit($resource);
        }

        return $limits;
    }
}
