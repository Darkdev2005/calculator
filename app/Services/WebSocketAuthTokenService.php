<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class WebSocketAuthTokenService
{
    public function generateForUser(User $user): string
    {
        $payload = [
            'uid' => $user->getKey(),
            'exp' => now()->addSeconds(config('calculator.token_ttl_seconds'))->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function resolveUser(?string $token): ?User
    {
        if (! $token) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! isset($payload['uid'], $payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < now()->timestamp) {
            return null;
        }

        return User::query()->find((int) $payload['uid']);
    }
}
