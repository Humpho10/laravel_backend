<?php

namespace App\Helpers;

use App\Models\User;
use App\Models\Notification;

class NotificationHelper
{
    public static function notifyAdmins($type, $referenceId, $message)
    {
        $admins = User::whereHas('roles', function ($q) {
            $q->where('name', 'Admin');
        })->get();

        foreach ($admins as $admin) {
            Notification::create([
                'user_id'      => $admin->id,
                'type'         => $type,
                'reference_id' => $referenceId,
                'message'      => $message,
                'is_read'      => false,
            ]);
        }
    }

    public static function notifySuperAdmins($type, $referenceId, $message)
    {
        $superAdmins = User::whereHas('roles', function ($q) {
            $q->where('name', 'Super-Admin');
        })->get();

        foreach ($superAdmins as $admin) {
            Notification::create([
                'user_id'      => $admin->id,
                'type'         => $type,
                'reference_id' => $referenceId,
                'message'      => $message,
                'is_read'      => false,
            ]);
        }
    }
}