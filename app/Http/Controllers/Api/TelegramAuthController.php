<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TelegramAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $tokenResponse = Http::asForm()->post('https://oauth.telegram.org/access_token', [
            'client_id' => env('TELEGRAM_CLIENT_ID'),
            'client_secret' => env('TELEGRAM_CLIENT_SECRET'),
            'redirect_uri' => env('TELEGRAM_REDIRECT_URI'),
            'grant_type' => 'authorization_code',
            'code' => $request->code,
        ]);

        if (!$tokenResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram token failed',
                'error' => $tokenResponse->json(),
            ], 401);
        }

        $accessToken = $tokenResponse->json('access_token');

        $userResponse = Http::withToken($accessToken)
            ->get('https://oauth.telegram.org/userinfo');

        if (!$userResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram user failed',
                'error' => $userResponse->json(),
            ], 401);
        }

        $telegramUser = $userResponse->json();

        $telegramId = (string) $telegramUser['sub'];

        $user = User::updateOrCreate(
            ['telegram_id' => $telegramId],
            [
                'uuid' => (string) Str::uuid(),
                'first_name' => $telegramUser['given_name'] ?? 'Telegram',
                'last_name' => $telegramUser['family_name'] ?? null,
                'email' => 'telegram_' . $telegramId . '@telegram.local',
                'telegram_username' => $telegramUser['username'] ?? null,
                'telegram_first_name' => $telegramUser['given_name'] ?? null,
                'telegram_last_name' => $telegramUser['family_name'] ?? null,
                'telegram_photo_url' => $telegramUser['picture'] ?? null,
                'password' => Hash::make(Str::random(32)),
                'role' => 'user',
                'status' => 'active',
            ]
        );

        $token = $user->createToken('telegram-auth')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login success',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => $user,
        ]);
    }
}