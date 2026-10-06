<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each merchant charges, where it is not what the steel merchant charges.
     *
     * Every coefficient in the cost model has been one figure per business since the model existed,
     * and one of them is a price per tonne of steel. LVL is timber. It is bought from a timber
     * merchant - the catalogue has said so all along, `LVL_Implementation` declares
     * `supplierGroup = TIMBER_MERCHANT` and orders are already split by it - and it was still being
     * costed at the steel rate, because `NestingCostModel` reads its coefficients off the Business
     * and nothing else.
     *
     * THE TWO ERRORS WERE CANCELLING, which is why nobody caught it. The LVL rows carry no kg/m, so
     * they fell back to default_kg_per_m at 10.0 against a true timber mass around 3.4 - three times
     * over - while being priced at $2,000/t against a timber price nearer $4,000/t. $20.00 a metre
     * by two compounding mistakes, where the honest arithmetic is around $14. Correcting the mass on
     * its own would have HALVED the price and made the model worse, which is the trap this column
     * exists to get out of: the mass and the rate have to be right together or not at all.
     *
     * Keyed by SUPPLIER GROUP rather than by material. The supplier group is what the application
     * already buys in - every ProductImplementation declares one, every order on a batch is split by
     * one, and a piece can be asked for its own - so it is the axis that already reaches from a piece
     * spec to a purchase order without anything new being threaded through. Material is the finer
     * axis and would be the right one for stainless, which is dearer than plain carbon and comes from
     * the same merchant; the catalogue holds exactly one stainless row today and it is a bolt, which
     * is nested by counting packs and never costed. See Services\SupplierGroupCosts.
     *
     * JSON rather than a table or a column each, for the reasons batches.nesting_settings is JSON:
     * what a reader wants is the whole override set as it stood, never a column to query on, and
     * five coefficients across five supplier groups is twenty-five columns that would be null almost
     * everywhere. It also means the set rides inside the retained snapshot without the snapshot
     * learning anything new - a batch nested today keeps the timber rate it was nested on.
     *
     * Null, not an empty object, for a business that has never set one. Nothing in the application
     * distinguishes the two, but "{}" is a decision somebody made and null is the absence of one.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('cost_overrides')->nullable()->after('purchase_cost_weight');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('cost_overrides');
        });
    }
};
