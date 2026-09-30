<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    /**
     * A supplier row is shared: several businesses can attach the same steel merchant, and the
     * business_supplier pivot is the only record of who has. So "owned" here means attached to the
     * caller's own business, not created by them.
     *
     * Without this, SupplierController::update took an implicitly bound {supplier} and wrote to it -
     * so PUT /suppliers/{id} renamed any supplier in the table, including one attached only to
     * another company. Supplier names go out on quote requests and purchase orders, so that was a
     * write to data that leaves the building.
     *
     * Admins pass: the admin supplier screen edits another business's list on purpose, and its form
     * posts to this same route (see AdminSuppliersIndex.vue, which picks the admin route for store
     * but not for update).
     */
    public function owned(User $user, Supplier $supplier): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $businessId = $user->business_id;

        if ($businessId === null) {
            return false;
        }

        return $supplier->businesses()
            ->whereKey($businessId)
            ->exists();
    }
}
