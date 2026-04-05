<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $creator = app(CreateNewUser::class);
            $user = $creator->create($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $token = $user->createToken('fantasy-app', ['openid', 'profile', 'email', 'wallet', 'gaming:history']);

        return response()->json([
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
            ],
            'access_token' => $token->accessToken,
            'expires_in' => 3600,
        ], 201);
    }
}
