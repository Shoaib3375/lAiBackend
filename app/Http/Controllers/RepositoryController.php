<?php
namespace App\Http\Controllers;

use App\Models\Repository;
use App\Services\GitHubService;
use Illuminate\Http\Request;

class RepositoryController extends Controller
{
    public function __construct(private GitHubService $github) {}

    /** GET /api/repos — list connected repos */
    public function index()
    {
        return auth()->user()->repositories()
            ->withCount(['pullRequests', 'pullRequests as open_prs_count' => fn($q) =>
            $q->where('status', 'pending')])
            ->latest()
            ->get();
    }

    /** GET /api/repos/available — repos on GitHub not yet connected */
    public function available()
    {
        $user = auth()->user();
        $ghRepos = $this->github->listUserRepos(decrypt($user->github_token));
        $connected = $user->repositories->pluck('repo_full_name')->toArray();

        return collect($ghRepos)
            ->filter(fn($r) => !in_array($r['full_name'], $connected))
            ->values();
    }

    /** POST /api/repos — connect repo + install webhook */
    public function connect(Request $request)
    {
        $request->validate([
            'repo_full_name' => 'required|string',
            'provider'       => 'required|in:github,gitlab',
        ]);

        $user   = auth()->user();
        $secret = bin2hex(random_bytes(20));

        // Install webhook on GitHub
        $this->github->installWebhook(
            decrypt($user->github_token),
            $request->repo_full_name,
            $secret
        );

        $repo = Repository::create([
            'user_id'        => $user->id,
            'repo_full_name' => $request->repo_full_name,
            'provider'       => $request->provider,
            'webhook_secret' => $secret,
            'is_active'      => true,
        ]);

        return response()->json($repo, 201);
    }

    /** DELETE /api/repos/{id} */
    public function disconnect(Repository $repository)
    {
        $this->authorize('delete', $repository);

        $this->github->removeWebhook(
            decrypt(auth()->user()->github_token),
            $repository->repo_full_name
        );

        $repository->delete();
        return response()->json(null, 204);
    }
}
