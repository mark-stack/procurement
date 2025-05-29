<?php

use App\Formatters\NestingFormatter;
use App\Models\Business;
use Illuminate\Support\Facades\Route;
use App\Models\RawMaterialQuote;
use App\Models\User;
use App\Services\DataClassificationService;
use App\Services\NotificationService;
use App\Services\ProductService;

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

    $result = (new NestingFormatter())->meterageAlgorithm(
        $cutLengthsRequired,
        $purchasableStockLengths,
        $offcutInventory,
        $lettersProjectArray,
        $business,
        null,
    );

    dd("13x 1000mm",$result);
});

Route::get('match', function () {
    $productService = new ProductService();
    $business = Business::first();
    $rawMaterialQuote = RawMaterialQuote::query()
        ->where("description","100 PFC 9000 mm")
        ->first();

    $getProductMatchOptions = $productService->getProductMatchOptions($business, $rawMaterialQuote);
    dd($getProductMatchOptions,$rawMaterialQuote);
});

Route::get('test', function () {

    $dataClassificationService = new DataClassificationService;

    $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText(
        "180mm Parallel Flange Channel",
        User::first(),
    );

    dd($generalProductMatches);
});

Route::get('notifications', function () {
    $user = auth()->user();

    //generate notifications
    //HourlyNotificationsJob::dispatchSync();

    $notifications = (new NotificationService)->getUnreadNotifications($user);

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

    dd('notifications', $notifications, $desiredNotificationClass);
});
