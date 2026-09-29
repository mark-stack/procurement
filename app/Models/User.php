<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Relationships
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * Annotated for the same reason Business::templates() is: without it every caller of
     * $user->business gets a plain Model, so the Business's own methods - billingState(),
     * allowsWrites() - are invisible to static analysis at each of the dozen places that read them.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    //Collections
    public function productsOrdered(): Collection
    {
        $products = [];
        foreach ($this->orders as $order) {
            foreach ($order->products as $product) {
                $products[] = $product;
            }
        }

        return collect($products);
    }

    public function suppliersOrderedFrom(): Collection
    {
        $suppliers = [];
        foreach ($this->orders as $order) {
            $suppliers[] = $order->supplier;
        }

        return collect($suppliers);
    }

    //Boolean
    public function isAdmin(): bool
    {
        return $this->email === config('env.admin_email');
    }

    //Strings
    public function getDomainFromEmail(): ?string
    {
        return self::domainFromEmail($this->email);
    }

    /**
     * The domain half of an email address, lower-cased, or null if there isn't one.
     *
     * This decides which Business a registration joins, so being approximately right is
     * worse than useless. The regex here before was /@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/,
     * unanchored and with the top-level domain capped at six letters, which meant
     * someone@shop.international matched only as far as it could and joined a business
     * called "shop.intern" - a name that is not theirs and that a genuine shop.intern
     * would later be merged into.
     *
     * Everything after the last @ is the domain, by definition: the local part may itself
     * contain an @ inside quotes, the domain may not. A dot is required so that a bare
     * hostname doesn't pass for one.
     *
     * Static because App\Rules\BusinessEmailDomain has to judge an address before any
     * User exists to ask, and the two must agree on what the domain is.
     */
    public static function domainFromEmail(?string $email): ?string
    {
        $domain = strtolower(trim(Str::afterLast((string) $email, '@')));

        if ($domain === '' || ! str_contains($domain, '.')) {
            return null;
        }

        return $domain;
    }
}
