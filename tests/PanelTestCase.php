<?php

namespace Tests;

use App\Auth\PanelSessions;
use App\Models\User;

abstract class PanelTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.rate_store' => 'database']);
    }

    protected function panel(User $user): array
    {
        $session = (new PanelSessions)->create($user);
        $this->withCredentials()->withHeader('Origin', 'http://localhost')
            ->withHeader('X-CSRF-Token', $session['store']->token())
            ->withUnencryptedCookie(PanelSessions::COOKIE, $session['token']);

        return $session;
    }
}
