<?php
// Fail closed on vendor upgrades; no runtime rewriting or verification bypass.
$path = $argv[1] ?? '/fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Api/v1/DriverController.php';
if (hash_file('sha256', $path) !== '73a460557277c147068f833d7933450215eebb01877923954add1a93e8c83fe4') {
    throw new RuntimeException('Unexpected DriverController base; review the authentication patch before upgrading.');
}
$source = file_get_contents($path);
$start = strpos($source, '    public function verifyCode(Request $request)');
$end = strpos($source, "\n    /**", $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Driver OTP method boundary missing.');
}
$method = <<<'METHOD'
    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $user = static::findDriverUserByIdentity($identity);
        if (!$user || static::isLoginDeactivated($user)) {
            return response()->apiError('Invalid verification code.');
        }
        $driver = Driver::where('user_uuid', $user->uuid)->where('company_uuid', session('company'))->first();
        if (!$driver) {
            return response()->apiError('Invalid verification code.');
        }
        try {
            // Lock and consume in the same transaction as token creation. Two
            // concurrent requests can never exchange one code for two tokens.
            $token = DB::transaction(function () use ($request, $user, $driver) {
                $verification = VerificationCode::where([
                    'subject_uuid' => $user->uuid,
                    'code' => $request->input('code'),
                    'for' => 'driver_login',
                ])->where('expires_at', '>', now())->lockForUpdate()->first();
                if (!$verification) {
                    return null;
                }
                $verification->delete();
                $token = $user->createToken($driver->uuid);
                DB::table('drivers')->where('uuid', $driver->uuid)->update(['auth_token' => $token->plainTextToken]);
                return $token;
            });
        } catch (\Throwable $e) {
            // Do not expose database errors, tokens or submitted codes.
            return response()->apiError('Unable to authenticate driver.');
        }
        if (!$token) {
            return response()->apiError('Invalid verification code.');
        }
        $driver->token = $token->plainTextToken;
        return new DriverResource($driver);
    }
METHOD;
$source = substr_replace($source, $method . "\n", $start, $end - $start);
// Authentication through an organization credential must resolve a driver in
// that same organization, including password login and recovery requests.
$source = str_replace("whereHas('driver')", "whereHas('driver', fn (\$query) => \$query->where('company_uuid', session('company')))", $source, $scopes);
if ($scopes !== 3) {
    throw new RuntimeException('Driver identity scope patch did not apply exactly three times.');
}
$companyStart = strpos($source, '    private static function getDriverCompanyFromUser(User $user): ?Company');
$companyEnd = strpos($source, "\n    /**", $companyStart);
if ($companyStart === false || $companyEnd === false) {
    throw new RuntimeException('Driver company method boundary missing.');
}
$companyMethod = <<<'METHOD'
    private static function getDriverCompanyFromUser(User $user): ?Company
    {
        return Company::where('uuid', session('company'))
            ->whereHas('drivers', fn ($query) => $query->where('user_uuid', $user->uuid))->first();
    }
METHOD;
$source = substr_replace($source, $companyMethod . "\n", $companyStart, $companyEnd - $companyStart);
$before = <<<'METHOD'
        $verification = static::findResetCode($user, $code);

        if (!$verification) {
            // One message for a wrong code, an expired code and an unknown
            // identity alike.
            return response()->apiError('Invalid or expired reset code.', 422);
        }

        $user->password = $password;
        $user->save();

        $verification->delete();
        $user->tokens()->delete();
METHOD;
$after = <<<'METHOD'
        $reset = DB::transaction(function () use ($user, $code, $password) {
            $verification = VerificationCode::where([
                'subject_uuid' => $user->uuid, 'code' => $code, 'for' => 'driver_password_reset',
            ])->where('expires_at', '>', now())->lockForUpdate()->first();
            if (!$verification) {
                return false;
            }
            $verification->delete();
            $user->password = $password;
            $user->save();
            $user->tokens()->delete();
            return true;
        });
        if (!$reset) {
            return response()->apiError('Invalid or expired reset code.', 422);
        }
METHOD;
$source = str_replace($before, $after, $source, $resets);
if ($resets !== 1 || file_put_contents($path, $source) === false) {
    throw new RuntimeException('Could not apply transactional driver recovery patch.');
}
