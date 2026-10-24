<?php

namespace App\Auth;

use Illuminate\Session\Store;

class PanelStore extends Store
{
    protected const SESSION_ID_LENGTH = 64;

    public function isValidId($id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{64}$/D', $id) === 1;
    }

    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
