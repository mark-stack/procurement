<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * One thing a business can buy, as the application describes it.
 *
 * The name, interval and price live here in config/billing.php, not in a provider's dashboard, so
 * the billing page can price a plan without a network call and so the same plan survives a change
 * of provider. All a provider contributes is its own identifier for it - see $prices.
 */
final class Plan
{
    /**
     * @param  string  $key  how the app and its URLs refer to this plan, e.g. "weekly"
     * @param  string  $interval  week|month|year
     * @param  int  $amount  minor units (cents), excluding GST
     * @param  array<string, string|null>  $prices  driver name => that provider's price identifier
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $interval,
        public readonly int $amount,
        public readonly string $currency,
        public readonly bool $public,
        public readonly array $prices,
    ) {}

    /**
     * @param  array<string, mixed>  $config  one entry out of config('billing.plans')
     */
    public static function fromConfig(string $key, array $config): self
    {
        foreach (['name', 'interval', 'amount'] as $required) {
            if (! array_key_exists($required, $config)) {
                throw new InvalidArgumentException("Billing plan [{$key}] is missing [{$required}].");
            }
        }

        return new self(
            key: $key,
            name: (string) $config['name'],
            interval: (string) $config['interval'],
            amount: (int) $config['amount'],
            currency: (string) ($config['currency'] ?? config('billing.currency')),
            public: (bool) ($config['public'] ?? true),
            prices: $config['prices'] ?? [],
        );
    }

    /**
     * The identifier the given provider knows this plan by, or null if it has none - which is how
     * a plan that has not been set up in the provider yet stays off the billing page instead of
     * failing at the checkout.
     */
    public function priceFor(string $provider): ?string
    {
        $price = $this->prices[$provider] ?? null;

        return ($price === null || $price === '') ? null : (string) $price;
    }

    /**
     * For emails and flash messages. The billing page formats client side instead, off $amount.
     */
    public function amountFormatted(): string
    {
        $symbol = match (strtolower($this->currency)) {
            'aud' => 'A$',
            'nzd' => 'NZ$',
            'usd' => 'US$',
            'gbp' => '£',
            'eur' => '€',
            default => strtoupper($this->currency).' ',
        };

        $major = $this->amount / 100;

        return $symbol.number_format($major, fmod($major, 1.0) === 0.0 ? 0 : 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /*
         * No price ids here on purpose. This is what the billing page receives, and a provider's
         * price identifier is not the browser's business.
         */
        return [
            'key' => $this->key,
            'name' => $this->name,
            'interval' => $this->interval,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->amountFormatted(),
        ];
    }
}
