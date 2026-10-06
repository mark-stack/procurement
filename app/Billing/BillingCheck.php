<?php

namespace App\Billing;

/**
 * One line of a go-live preflight: something that was checked, and how it came out.
 *
 * Three levels rather than a boolean, because the interesting answers are not pass and fail. A price
 * that does not exist is a failure; a price that exists and is tax-inclusive while GST collection is
 * off is a decision somebody should look at before the first card is charged, and it should not stop
 * a deploy.
 */
final class BillingCheck
{
    /**
     * Checked, and it is as it should be.
     */
    public const string OK = 'ok';

    /**
     * Legal, but probably not what was meant. Reported, and does not fail the command.
     */
    public const string WARN = 'warn';

    /**
     * Would sell nothing, or sell the wrong thing. Fails the command.
     */
    public const string FAIL = 'fail';

    private function __construct(
        public readonly string $level,
        public readonly string $subject,
        public readonly string $detail,
    ) {}

    public static function ok(string $subject, string $detail): self
    {
        return new self(self::OK, $subject, $detail);
    }

    public static function warn(string $subject, string $detail): self
    {
        return new self(self::WARN, $subject, $detail);
    }

    public static function fail(string $subject, string $detail): self
    {
        return new self(self::FAIL, $subject, $detail);
    }

    public function failed(): bool
    {
        return $this->level === self::FAIL;
    }

    public function warned(): bool
    {
        return $this->level === self::WARN;
    }
}
