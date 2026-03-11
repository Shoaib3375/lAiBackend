<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
class AuthController extends Controller
{
    /**
     * Redirect to GitHub for OAuth
     * GET /auth/github
     */
    public function redirectToGithub()
    {
        return Socialite::driver('github')
            ->scopes(['repo', 'read:org', 'admin:repo_hook'])
            ->redirect();
    }

    /**
     * Handle GitHub OAuth callback
     * GET /auth/github/callback
     */
    public function handleGithubCallback()
    {
        $githubUser = Socialite::driver('github')->user();

        $user = User::updateOrCreate(
            ['github_id' => $githubUser->id],
            [
                'name'         => $githubUser->name,
                'email'        => $githubUser->email,
                'github_token' => encrypt($githubUser->token),
                'avatar'       => $githubUser->avatar,
                'github_login' => $githubUser->nickname,
            ]
        );

        // Issue Sanctum token
        $token = $user->createToken('prdock-app')->plainTextToken();

        return redirect(env('FRONTEND_URL') . '/auth/callback?token=' . $token);
    }

    /**
     * Get authenticated user
     * GET /api/me
     */
    public function me()
    {
        return response()->json(auth()->user()->load('activeSubscription'));
    }

    /**
     * Revoke token + logout
     * POST /api/logout
     */
    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
