<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalogue becomes something an admin edits row by row rather than re-imports wholesale,
     * and every edit has to first answer "what already uses this product?".
     *
     * That question is answered by matching the product's spec columns against pieces, bars and
     * offcuts, none of which carry a product_id - they denormalise the spec instead. Without an
     * index that is a full scan of every piece, bar and offcut on the platform, once per screen.
     *
     * TEXT cannot be indexed on MySQL without a prefix length, so the category narrows to a
     * varchar first. It holds a ProductEnums value - twelve characters at the widest - and 64
     * matches the width offcuts.product_category was already narrowed to for the same reason.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_category', 64)->change();
        });

        /*
         * Serves both readers of this table: the admin catalogue screen, which filters by category
         * and by deprecated, and every nest, which resolves purchasable stock lengths through
         * availableForBusiness() before narrowing by spec.
         *
         * Guarded, like every step here. DDL is not transactional on MySQL, so a run that fails part
         * way leaves the schema between the two states and re-running it has to be able to finish
         * the job rather than fall over on the first thing it already did.
         */
        if (! Schema::hasIndex('products', 'products_business_deprecated_category_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(
                    ['business_id', 'deprecated', 'product_category'],
                    'products_business_deprecated_category_index',
                );
            });
        }

        foreach (['pieces', 'bars'] as $usageTable) {
            Schema::table($usageTable, function (Blueprint $table) {
                $table->string('product_category', 64)->change();
            });

            if (! Schema::hasIndex($usageTable, $usageTable.'_product_category_index')) {
                Schema::table($usageTable, function (Blueprint $table) use ($usageTable) {
                    $table->index('product_category', $usageTable.'_product_category_index');
                });
            }
        }

        /*
         * offcuts.product_category is already a varchar(64), narrowed when offcut marks were scoped
         * to a business. Its unique index leads with business_id, so it cannot answer a lookup by
         * category alone.
         */
        if (! Schema::hasIndex('offcuts', 'offcuts_product_category_index')) {
            Schema::table('offcuts', function (Blueprint $table) {
                $table->index('product_category', 'offcuts_product_category_index');
            });
        }
    }

    /**
     * Every step is guarded by whether it is actually there.
     *
     * DDL is not transactional on MySQL, so a rollback that fails half way through leaves the schema
     * between the two states - and re-running it then failed on the first index it had already
     * dropped, with no way back but by hand.
     */
    public function down(): void
    {
        if (Schema::hasIndex('offcuts', 'offcuts_product_category_index')) {
            Schema::table('offcuts', function (Blueprint $table) {
                $table->dropIndex('offcuts_product_category_index');
            });
        }

        foreach (['pieces', 'bars'] as $usageTable) {
            if (Schema::hasIndex($usageTable, $usageTable.'_product_category_index')) {
                Schema::table($usageTable, function (Blueprint $table) use ($usageTable) {
                    $table->dropIndex($usageTable.'_product_category_index');
                });
            }

            Schema::table($usageTable, function (Blueprint $table) {
                $table->text('product_category')->change();
            });
        }

        if (Schema::hasIndex('products', 'products_business_deprecated_category_index')) {
            /*
             * products.business_id has a foreign key, and MySQL will not drop the last index that
             * can serve one. The original single-column index was absorbed the moment the composite
             * index below covered business_id as its leftmost column, so there is nothing left for
             * the constraint to fall back on - "Cannot drop index: needed in a foreign key
             * constraint", and the rollback died here with the products table already converted.
             *
             * Give the constraint its own index back first.
             */
            if (! Schema::hasIndex('products', 'products_business_id_foreign')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->index('business_id', 'products_business_id_foreign');
                });
            }

            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_business_deprecated_category_index');
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->text('product_category')->change();
        });
    }
};
