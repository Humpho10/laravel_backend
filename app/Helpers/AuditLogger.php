<?php

namespace App\Helpers;

use App\Models\AuditTrail;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public static function log(
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldValue = null,
        ?array $newValue = null
    ): void {
        AuditTrail::create([
            'user_id'    => Auth::id(),
            'table_name' => $tableName,
            'record_id'  => $recordId,
            'action'     => $action,
            'old_value'  => $oldValue,
            'new_value'  => $newValue,
        ]);
    }
}