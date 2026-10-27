<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

class PublicReadSnapshot
{
    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public function run(Closure $read): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction($read);
    }
}
