<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function owned(User $user, Order $order): bool
    {
        /**
         * Is a user of this business
         */
        return $order->user->business->id === $user->business->id;
    }
}
