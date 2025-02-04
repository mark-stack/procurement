<?php

//todo: experimental
use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\RawMaterialQuote;
use App\Models\User;
use App\Services\DataClassificationService;
use App\Services\NotificationService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

//todo temporary
Route::get('pickles', function () {
    $admin = User::query()
        ->where('email', env('ADMIN_EMAIL'))
        ->firstOrFail();

    Auth::login($admin);

    return redirect()->route('admin.users.index');
});

//todo temporary
Route::get('stock-cutting', function () {
    // Example Usage:
    $cutLengthsRequired = [];
    for ($i = 1; $i <= 13; $i++) {
        $cutLengthsRequired[] = [
            'project' => 1,
            'length' => 1000,
        ];
    }

    $purchasableStockLengths = [8000];

    $offcutInventory = [
        [
            "length" => 6000,
            "id" => 1,
            "batch_from_id" => 1,
        ],
        [
            "length" => 1500,
            "id" => 2,
            "batch_from_id" => 1,
        ],
        [
            "length" => 1200,
            "id" => 3,
            "batch_from_id" => 1,
        ],
    ];

    $lettersProjectArray[1] = "A";
    $business = Business::first();

    $result = (new NestingFormatter)->meterageAlgorithm(
        $cutLengthsRequired,
        $purchasableStockLengths,
        $offcutInventory,
        $lettersProjectArray,
        $business,
        null,
    );

    dd("13x 1000mm",$result);
});

//todo temporary
Route::get('match', function () {
    $productService = new ProductService();
    $business = Business::first();
    $rawMaterialQuote = RawMaterialQuote::query()
        ->where("description","100 PFC 9000 mm")
        ->first();

    $getProductMatchOptions = $productService->getProductMatchOptions($business, $rawMaterialQuote);
    dd($getProductMatchOptions,$rawMaterialQuote);
});

//todo temporary
Route::get('test', function () {

    $stockLengths = [9000, 12000]; // Available stock lengths
    $pieceLengths = [1000,1000,1000,1000,1000,1000,1000,1000,1000,1000]; // Pieces to cut

    sort($stockLengths); // Ensure stock lengths are sorted (smallest to largest)
    rsort($pieceLengths); // Start with the longest pieces to reduce waste

    $results = [];
    for ($i = 1; $i <= config('env.nesting_iterations'); $i++) {
        $bins = []; // Store used stock pieces

        foreach ($pieceLengths as $piece) {
            $bestBinIndex = -1;
            $minRemaining = PHP_INT_MAX;

            // Find the best bin (smallest remaining space that still fits the piece)
            foreach ($bins as $index => $bin) {
                if ($bin['remaining'] >= $piece && ($bin['remaining'] - $piece) < $minRemaining) {
                    $bestBinIndex = $index;
                    $minRemaining = $bin['remaining'] - $piece;
                }
            }

            // Place the piece in the best-fit bin
            if ($bestBinIndex != -1) {
                $bins[$bestBinIndex]['cuts'][] = $piece;
                $bins[$bestBinIndex]['remaining'] -= $piece;
            }
            else {
                // If no bin fits, open a new stock piece
                $randomKey = array_rand($stockLengths);
                $randomStockLength = $stockLengths[$randomKey];

                if($randomStockLength >= $piece){
                    $bins[] = [
                        'stock' => $randomStockLength,
                        'remaining' => $randomStockLength - $piece,
                        'cuts' => [$piece]
                    ];
                }
            }
        }

        /*
         * sums
         */
        $totalPurchasedMaterial = 0;
        $totalWaste = 0;
        foreach($bins as $bin){
            $totalPurchasedMaterial = $totalPurchasedMaterial + $bin["stock"];
            $totalWaste = $totalWaste + $bin["remaining"];
        }
        $totalUsed = $totalPurchasedMaterial - $totalWaste;
        $efficiency = round($totalUsed/$totalPurchasedMaterial*100);

        $results[$efficiency] = $bins;
    }

    $highestEfficiencyKey = max(array_keys($results));
    dd($highestEfficiencyKey,$results[$highestEfficiencyKey]);
});
//todo temporary
Route::get('notifications', function () {
    $user = auth()->user();

    //generate notifications
    //HourlyNotificationsJob::dispatchSync();

    $notifications = (new NotificationService)->getUnreadNotifications($user);
    //dd(3,$notifications);
    //Find Notification implementation
    $desiredNotificationClassName = "App\Services\NotificationImplementations\NotificationQuotingOrderingOverDueImplementation";
    $desiredNotificationClass = null;

    $implementations = (new NotificationService)->getImplementations();
    foreach ($implementations as $implementation) {
        $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

        // Check if the class exists
        if (class_exists($className)) {
            $service = new $className;

            if ($className === $desiredNotificationClassName) {
                $desiredNotificationClass = $service;
            }
        }
    }

    dd(3, $desiredNotificationClass->hourlyCheck());

    dd('notifications', $notifications, $desiredNotificationClass);
});

require __DIR__.'/auth.php';
require __DIR__.'/authRoutes.php';
require __DIR__.'/guestRoutes.php';
require __DIR__.'/adminRoutes.php';
