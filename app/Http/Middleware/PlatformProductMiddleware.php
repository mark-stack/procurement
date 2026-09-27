<?php

namespace App\Http\Middleware;

use App\Models\Product;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The master materials screen edits the platform catalogue and nothing else.
 *
 * Implicit route binding resolves {product} by id alone, so a mistyped or guessed id reaches a
 * business's own private product - one they added themselves from a BOM line the catalogue could not
 * match. Editing or deleting that from here would change another company's price book, and the only
 * sign of it would be their product quietly disappearing.
 */
class PlatformProductMiddleware
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $product = $request->route('product');

        if ($product instanceof Product && $product->business_id !== null) {
            abort(404);
        }

        return $next($request);
    }
}
