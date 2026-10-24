<?php

namespace App\Media;

use App\Models\User;
use Closure;

class MediaConsumers
{
    /** @var array<string, array{read: Closure, public: Closure}> */
    private array $consumers = [];

    /** Register trusted server-side consumer resolvers; never accept resolvers/public flags from requests. */
    public function register(string $type, Closure $canRead, Closure $isPublic): void
    {
        $this->consumers[$type] = ['read' => $canRead, 'public' => $isPublic];
    }

    public function readable(string $type, int $id, User $actor): bool
    {
        if ($type === 'user') {
            return User::withTrashed()->whereKey($id)->whereNotNull('username_normalized')->exists();
        }

        return isset($this->consumers[$type]) && ($this->consumers[$type]['read'])($id, $actor);
    }

    public function public(string $type, int $id): bool
    {
        return isset($this->consumers[$type]) && ($this->consumers[$type]['public'])($id);
    }

    public function registered(string $type): bool
    {
        return $type === 'user' || isset($this->consumers[$type]);
    }
}
