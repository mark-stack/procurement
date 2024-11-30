<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
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

    public function customProducts(): Collection
    {
        $domain = (new ProductService())->getDomainFromEmail($this->email);
        return Product::query()
            ->where("domain",$domain)
            ->get();
    }

    //Boolean
    public function isAdmin(): bool
    {
        return $this->email === env('ADMIN_EMAIL');
    }
}
