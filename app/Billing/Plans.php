<?php

namespace App\Billing;

use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The plan catalogue, read once out of config/billing.php.
 *
 * Resolved as a singleton, so the config parsing and validation in Plan::fromConfig happens once
 * per request rather than on every read - the billing banner asks for the current plan on every
 * Inertia response.
 */
final class Plans
{
    /** @var array<string, Plan>|null */
    private ?array $plans = null;

    /**
     * @return array<string, Plan>
     */
    public function all(): array
    {
        if ($this->plans !== null) {
            return $this->plans;
        }

        $plans = [];

        foreach ((array) config('billing.plans', []) as $key => $config) {
            $plans[(string) $key] = Plan::fromConfig((string) $key, Arr::wrap($config));
        }

        return $this->plans = $plans;
    }

    public function find(string $key): ?Plan
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * A plan key arrives from a URL, so an unknown one is a 404 rather than a 500.
     */
    public function findOrFail(string $key): Plan
    {
        return $this->find($key) ?? throw new NotFoundHttpException("Unknown billing plan [{$key}].");
    }

    /**
     * The plans meant to be shown on the billing page, in config order.
     *
     * Whether one can actually be bought is the live provider's call, not this class's - a plan is
     * purchasable by invoice without any price id existing anywhere. Billing::sellablePlans()
     * narrows this list by asking the driver.
     *
     * @return array<int, Plan>
     */
    public function published(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Plan $plan): bool => $plan->public,
        ));
    }
}
