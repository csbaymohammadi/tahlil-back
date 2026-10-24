<?php

namespace App\Auth;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SessionHandlerInterface;

class PanelSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $payload = DB::table('panel_sessions')->where('token_hash', hash('sha256', $id))
            ->whereNull('revoked_at')->where('absolute_expires_at', '>', now())
            ->where('idle_expires_at', '>', now())->value('payload');

        return $payload ? Crypt::decryptString($payload) : '';
    }

    public function write(string $id, string $data): bool
    {
        DB::table('panel_sessions')->where('token_hash', hash('sha256', $id))
            ->whereNull('revoked_at')->where('absolute_expires_at', '>', now())
            ->where('idle_expires_at', '>', now())->update(['payload' => Crypt::encryptString($data)]);

        return true;
    }

    public function destroy(string $id): bool
    {
        DB::table('panel_sessions')->where('token_hash', hash('sha256', $id))->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => 'rotation']);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}
