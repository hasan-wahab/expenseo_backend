<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class RealtimeConfigController extends Controller
{
    /**
     * Flutter app ko Reverb (Pusher protocol) connection details deta hai.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $scheme = config('broadcasting.connections.reverb.options.scheme', 'http');
        $host = config('reverb.client_host')
            ?: config('broadcasting.connections.reverb.options.host', 'localhost');
        $port = (int) config('broadcasting.connections.reverb.options.port', 8080);

        return response()->json([
            'message' => 'Realtime config retrieved successfully',
            'data' => [
                'driver' => 'reverb',
                'protocol' => 'pusher',
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => $host,
                'port' => $port,
                'scheme' => $scheme,
                'useTLS' => $scheme === 'https',
                'auth_endpoint' => url('/api/broadcasting/auth'),
                'auth_header' => 'Authorization',
                'event' => 'synced',
                'user_channel' => 'private-user.'.$user->id,
                'property_channel_prefix' => 'private-property.',
            ],
        ]);
    }
}
