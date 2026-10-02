<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * users.business_id is nullable, so this can legitimately be null. It used to
         * be dereferenced straight away, which meant one orphaned user took down the
         * whole admin users list for every admin.
         */
        $business = $this->business;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            //The admin's own row must not offer an impersonate button - the controller
            //refuses it, and a button that always errors is worse than no button
            'isAdmin' => $this->resource->isAdmin(),
            /*
             * Impersonating an unverified user lands on the verification prompt rather than
             * the dashboard, and an unverified signup is exactly what an admin opens this
             * page to look at, so the page has to be able to say which is which.
             */
            'isVerified' => $this->resource->hasVerifiedEmail(),
            'created_at' => $this->created_at,
            /*
             * Spelled out rather than handing over the model. A model serializes every
             * column and every relation loaded on it, which is how this page came to ship
             * each business's templates and suppliers - screenshots included - twice per
             * row to render two numbers.
             */
            'business' => $business ? [
                'id' => $business->id,
                'domain' => $business->domain,
            ] : null,
            /*
             * How many templates are matched against uploads, how many exist at all, and the
             * suppliers - see the controller for why the first two are both here.
             *
             * Guarded the same way the business above is, rather than with "?->": the relation is
             * declared as returning a Business, so static analysis reads a null-safe access here as
             * a check that cannot fire and says so. It can fire - business_id is nullable.
             */
            'templates_count' => $business ? (int) $business->templates_count : 0,
            'templates_total' => $business ? (int) $business->templates_total : 0,
            'suppliers_count' => $business ? (int) $business->suppliers_count : 0,
            /*
             * On the user and not the business, unlike the two above: mail is addressed to a person.
             * A business whose owner has had four emails and whose draftsman has had none is the
             * normal case, and one number across the pair would hide exactly that.
             *
             * Never styled as a problem in the template, however low. Unlike templates - where 0
             * means a customer cannot import - 0 emails is the ordinary state of a signup that has
             * needed nothing sent to it.
             */
            'emails_count' => (int) ($this->emails_count ?? 0),
        ];
    }
}
