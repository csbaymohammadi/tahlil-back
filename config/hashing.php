<?php

return [
    'driver' => 'argon2id',
    'bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 12), 'verify' => true],
    'argon' => ['memory' => 19456, 'time' => 2, 'threads' => 1, 'verify' => false],
    'rehash_on_login' => true,
];
