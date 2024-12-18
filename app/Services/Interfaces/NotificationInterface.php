<?php

namespace App\Services\Interfaces;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

interface NotificationInterface
{
    public function hourlyCheck(): void;
    public function notifiedAlready(object $recipient, int $uniqueModelId): bool;
    public function sendNotification(object $recipient, object $otherObject): void;
    public function checkProjectChanges(Project $project): void;
    public function getNotificationClass(): string;
    public function markPreviousAsRead(object $recipient, object $otherObject): void;
    public function trafficLight(DatabaseNotification $notification, string $status): null|RedirectResponse;
    public function markGreen(DatabaseNotification $notification): RedirectResponse;
    public function markRed(DatabaseNotification $notification): RedirectResponse;
    public function markYellow(DatabaseNotification $notification): RedirectResponse;
    public function isCorrectClass(DatabaseNotification $notification): bool;
    public function notificationData(DatabaseNotification $notification): null|array;
    public function message(string $string_1, string $string_2): string;
}
