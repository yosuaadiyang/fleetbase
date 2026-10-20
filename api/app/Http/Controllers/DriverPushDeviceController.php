<?php

namespace App\Http\Controllers;

use App\Support\{DriverPushDevices, SocketAccessTickets};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

final class DriverPushDeviceController
{
    public function store(Request $request)
    {
        $actor = $this->actor($request);
        if (!$actor || !$actor['driver']) return $this->reply(['errors' => ['Driver authentication required.']], 401);
        $bucket = 'contrust:push-register:' . $actor['token']->getKey();
        if (RateLimiter::tooManyAttempts($bucket, 20)) return $this->reply(['errors' => ['Please try again later.']], 429);
        RateLimiter::hit($bucket, 60);
        if (!DriverPushDevices::validInput($request->all())) return $this->reply(['errors' => ['Invalid Android device registration.']], 422);
        $installation = DriverPushDevices::register($actor, $request->all());
        return $this->reply(['status' => 'registered', 'installation_id' => $installation]);
    }

    public function destroy(Request $request)
    {
        $actor = $this->actor($request);
        if (!$actor || !$actor['driver']) return $this->reply(['errors' => ['Driver authentication required.']], 401);
        if ($request->all()) return $this->reply(['errors' => ['Logout accepts no account overrides.']], 422);
        DriverPushDevices::revokeSession($actor['token']);
        return $this->reply(['status' => 'signed_out']);
    }

    private function actor(Request $request): ?array
    {
        $bearer = $request->bearerToken();
        return SocketAccessTickets::actor(is_string($bearer) && str_contains($bearer, '|') ? PersonalAccessToken::findToken($bearer) : null);
    }

    private function reply(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
