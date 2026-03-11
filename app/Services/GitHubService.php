<?php
namespace App\Services;

use Illuminate\Support\Facades\{Http, Cache};

class GitHubService
{
    private string $base = 'https://api.github.com';

    /** List repos user has access to */
    public function listUserRepos(string $token): array
    {
        return Http::withToken($token)
            ->get($this->base . '/user/repos', [
                'per_page'   => 100,
                'sort'       => 'pushed',
                'visibility' => 'all',
            ])->json();
    }

    /** Install webhook on a specific repo */
    public function installWebhook(string $token, string $fullName, string $secret): array
    {
        return Http::withToken($token)
            ->post($this->base . "/repos/{$fullName}/hooks", [
                'name'   => 'web',
                'active' => true,
                'events' => ['pull_request'],
                'config' => [
                    'url'          => route('webhook.github'),
                    'content_type' => 'json',
                    'secret'       => $secret,
                    'insecure_ssl' => '0',
                ],
            ])->json();
    }

    /** Remove our webhook from a repo */
    public function removeWebhook(string $token, string $fullName, ?int $hookId = null): void
    {
        if ($hookId) {
            Http::withToken($token)
                ->delete($this->base . "/repos/{$fullName}/hooks/{$hookId}");
            return;
        }
        // Fallback: find and remove by URL
        $hooks = Http::withToken($token)
            ->get($this->base . "/repos/{$fullName}/hooks")->json();
        $appUrl = route('webhook.github');
        foreach ($hooks as $hook) {
            if (($hook['config']['url'] ?? '') === $appUrl) {
                Http::withToken($token)
                    ->delete($this->base . "/repos/{$fullName}/hooks/{$hook['id']}");
            }
        }
    }

    /**
     * Get a GitHub App installation token.
     * Used when posting PR review comments as the GitHub App bot.
     * Cached for 55 min (tokens expire at 60 min).
     */
    public function getInstallationToken(string $installationId): string
    {
        return Cache::remember(
            "gh_install_token_{$installationId}",
            3300, // 55 minutes
            function () use ($installationId) {
                $jwt = $this->generateAppJwt();
                $response = Http::withToken($jwt)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->post($this->base . "/app/installations/{$installationId}/access_tokens")
                    ->json();
                return $response['token'];
            }
        );
    }

    /** Generate a short-lived JWT for GitHub App auth (valid 10 min) */
    private function generateAppJwt(): string
    {
        $now     = time();
        $payload = [
            'iat' => $now - 60,
            'exp' => $now + (10 * 60),
            'iss' => config('services.github.app_id'),
        ];
        $key = file_get_contents(config('services.github.private_key_path'));
        return \Firebase\JWT\JWT::encode($payload, $key, 'RS256');
        // composer require firebase/php-jwt
    }

    /** Get PR diff directly (for manual re-review) */
    public function getPrFiles(string $token, string $fullName, int $prNumber): array
    {
        return Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get($this->base . "/repos/{$fullName}/pulls/{$prNumber}/files", [
                'per_page' => 100,
            ])->json();
    }
}
