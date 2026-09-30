<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_sent' => 'boolean',
            'order_confirmation_received' => 'boolean',
            'is_delivered' => 'boolean',
        ];
    }

    //Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    //Optional
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function pieces(): BelongsToMany
    {
        return $this->belongsToMany(Piece::class);
    }

    //Optional
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return HasMany<MaterialCertificate, $this>
     */
    public function materialCertificates(): HasMany
    {
        return $this->hasMany(MaterialCertificate::class);
    }

    //Local scopes

    /**
     * Orders that are certified, in either of the two ways an order can be.
     *
     * "Certified" used to mean one thing - material_cert_numbers is not null - because a written
     * reference was all this could hold. Attached files count for exactly as much: a merchant who
     * emails the PDF and never quotes a number leaves the steel just as traceable as one who does
     * the reverse.
     *
     * Every read of this question goes through here, so the two cannot drift apart. They already
     * did once, in three separate places: the board's "no certs yet" warning, the new-stock trail
     * on the print spec, and the ancestry trail behind a reused offcut.
     */
    public function scopeHasMaterialCerts(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotNull('material_cert_numbers')
                ->orWhereHas('materialCertificates');
        });
    }

    public function scopeMissingMaterialCerts(Builder $query): Builder
    {
        return $query->whereNull('material_cert_numbers')
            ->whereDoesntHave('materialCertificates');
    }
}
