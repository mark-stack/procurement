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

/**
 * Declared for the same reason the Business billing columns are: casts() is a method, so static
 * analysis cannot see through it to the boolean this really is.
 *
 * @property bool $sandbox_mode Whether this user is currently working in their own sandbox
 * @property bool $is_admin Whether this user is the platform admin - see isAdmin()
 */
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
            /*
             * Cast for the same reason the Business flags are: without it the value reaching the
             * front end is whatever the driver hands back - an int on mysql, the string "0" on
             * sqlite - and "0" is truthy in JavaScript, so every page would claim to be in test
             * mode under the test connection.
             */
            'sandbox_mode' => 'boolean',
            //Same reason again, and it decides whether the admin panel opens
            'is_admin' => 'boolean',
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
    /**
     * The rest of the staff at this user's company, as a picker needs them: an id and a name.
     *
     * For choosing which project manager a material list is being uploaded for. The person with the
     * spreadsheet is often not the person running the job - a draftsman details it and uploads the
     * BOM for a colleague - and until projects.created_by_user_id existed every one of those projects
     * landed on the board under the draftsman's name, out of reach of the manager it was for.
     *
     * There is no staff list, no roles and no invitations in this application: a business is every
     * user whose email domain matched at registration, and they are all peers. So every colleague is
     * an equally valid answer and they come back in name order, with no notion of who is "a project
     * manager" - anyone can be, and the one who uploads for somebody else today is the one somebody
     * else uploads for tomorrow.
     *
     * Empty on a one-person business, which is most of them, and the forms draw nothing at all then.
     *
     * @return list<array{id: int, name: string}>
     */
    public function colleagueOptions(): array
    {
        return $this->business->users()
            ->select(['id', 'name'])
            ->where('id', '!=', $this->id)
            ->orderBy('name')
            ->get()
            ->map(fn (User $colleague) => [
                'id' => $colleague->id,
                'name' => $colleague->name,
            ])
            ->all();
    }

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
    /**
     * The platform admin, who can read and write every business's data.
     *
     * A column, not a comparison against config('env.admin_email'). This read the user's own email
     * until the 2026_09_30 migration, and a user may change that on /profile: typing the configured
     * address into your own profile made you the platform admin, with the master catalogue, every
     * business's templates and admin.impersonate - which logs in as any user of any business. Only a
     * migration or a console command sets the column, and $fillable does not carry it, so no request
     * can.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
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
