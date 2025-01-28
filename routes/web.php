<?php

//todo: experimental
use App\Jobs\HourlyNotificationsJob;
use App\Models\Project;
use App\Models\User;
use App\Services\DataClassificationService;
use App\Services\NestingService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

//todo temporary
Route::get("pickles",function(){
    $admin = User::query()
        ->where("email",env('ADMIN_EMAIL'))
        ->firstOrFail();

    Auth::login($admin);

    return redirect()->route("admin.users.index");
});

//todo temporary
Route::get("stock-cutting",function(){
    // Example Usage:
    $cutLengths = [1000,3000,4000,1000,5000,11000,4000,2000,2500,5000,13000];
    $stockLengths = [9000,12000];

    $result = (new NestingService())->meterageAlgorithm($cutLengths, $stockLengths);

    dd($result);
});
//todo temporary
Route::get("test",function(){
    $description = "20mm plate GR350";

    $dataClassificationService = new DataClassificationService();

    $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText($description,auth()->user());
    dd("web",$description,$generalProductMatches);
});
//todo temporary
Route::get("notifications",function(){
    $user = auth()->user();

    //generate notifications
    //HourlyNotificationsJob::dispatchSync();

    //$notifications = (new NotificationService())->getUnreadNotifications($user);

    //Find Notification implementation
    $desiredNotificationClassName = "App\Services\NotificationImplementations\NotificationQuotingOrderingDueImplementation";
    $desiredNotificationClass = null;

    $implementations = (new NotificationService())->getImplementations();
    foreach($implementations as $implementation){
        $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

        // Check if the class exists
        if (class_exists($className)) {
            $service = new $className();

            if($className === $desiredNotificationClassName){
                $desiredNotificationClass = $service;
            }
        }
    }

    dd(3,$desiredNotificationClass,$desiredNotificationClass->hourlyCheck());

    dd("notifications",$notifications,$desiredNotificationClass);
});

require __DIR__.'/auth.php';
require __DIR__.'/authRoutes.php';
require __DIR__.'/guestRoutes.php';
require __DIR__.'/adminRoutes.php';
