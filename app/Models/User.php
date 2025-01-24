<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

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

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    //Collections
    public function productsOrdered(): Collection
    {
        $products = [];
        foreach($this->orders as $order){
            foreach($order->products as $product){
                $products[] = $product;
            }
        }

        return collect($products);
    }

    public function suppliersOrderedFrom(): Collection
    {
        $suppliers = [];
        foreach($this->orders as $order){
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
        $pattern = '/@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/';
        if (preg_match($pattern, $this->email, $matches)) {
            return $matches[1]; // Domain is captured in the first group
        }
        return null; // Return null if no domain found
    }
}
