<?php

namespace App\Auth;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

class PanelCsrf extends PreventRequestForgery
{
    public function valid(Request $request): bool
    {
        return $this->tokensMatch($request);
    }

    protected function getTokenFromRequest($request): ?string
    {
        $token = $request->header('X-CSRF-Token');

        return is_string($token) ? $token : null;
    }
}
