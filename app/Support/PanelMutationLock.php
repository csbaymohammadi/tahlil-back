<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PanelMutationLock
{
    public static function acquire(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Panel mutations require a transaction.');
        }
        if (DB::table('panel_mutation_lock')->where('id', 1)->increment('revision') !== 1) {
            throw new RuntimeException('Panel mutation lock is not initialized.');
        }
    }
}
