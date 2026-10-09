<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use App\Http\Requests\RegisterDeviceTokenRequest;

class DeviceTokenController extends Controller
{
    /** App calls this on launch + token refresh so backend can push to the device. */
    public function store(RegisterDeviceTokenRequest $request)
    {
        $token = DeviceToken::updateOrCreate(
            ['token' => $request->token],
            [
                'user_id' => Helpers::getCurrentUserId(),
                'platform' => $request->platform ?? 'android',
                'app_version' => $request->app_version,
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );
        return response()->json(['message' => 'Device token registered', 'data' => $token]);
    }

    /** Call on logout so the next user of the phone doesn't get this user's pushes. */
    public function destroy(Request $request)
    {
        $request->validate(['token' => ['required', 'string']]);
        DeviceToken::where('token', $request->token)->update(['is_active' => false, 'user_id' => null]);
        return response()->json(['message' => 'Device token removed']);
    }
}
