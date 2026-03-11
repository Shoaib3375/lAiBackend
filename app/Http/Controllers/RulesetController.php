<?php
namespace App\Http\Controllers;

use App\Models\Ruleset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RulesetController extends Controller
{
    /** GET /api/rulesets */
    public function index()
    {
        return auth()->user()->rulesets()
            ->withCount('repositories')
            ->latest()->get();
    }

    /** POST /api/rulesets */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'language'   => 'nullable|string|max:50',
            'is_default' => 'boolean',
            'rules'      => 'required|array|min:1|max:30',
            'rules.*'    => 'required|string|max:300',
        ]);

        return DB::transaction(function () use ($data) {
            // Only one default per user
            if (!empty($data['is_default'])) {
                auth()->user()->rulesets()->update(['is_default' => false]);
            }

            $ruleset = auth()->user()->rulesets()->create($data);
            return response()->json($ruleset, 201);
        });
    }

    /** GET /api/rulesets/{ruleset} */
    public function show(Ruleset $ruleset)
    {
        $this->authorize('view', $ruleset);
        return $ruleset->load('repositories:id,repo_full_name');
    }

    /** PUT /api/rulesets/{ruleset} */
    public function update(Request $request, Ruleset $ruleset)
    {
        $this->authorize('update', $ruleset);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'language'   => 'nullable|string|max:50',
            'is_default' => 'boolean',
            'rules'      => 'sometimes|array|min:1|max:30',
            'rules.*'    => 'string|max:300',
        ]);

        DB::transaction(function () use ($data, $ruleset) {
            if (!empty($data['is_default'])) {
                auth()->user()->rulesets()->where('id', '!=', $ruleset->id)
                    ->update(['is_default' => false]);
            }
            $ruleset->update($data);
        });

        return response()->json($ruleset->fresh());
    }

    /** DELETE /api/rulesets/{ruleset} */
    public function destroy(Ruleset $ruleset)
    {
        $this->authorize('delete', $ruleset);

        // Unlink repos before deleting
        $ruleset->repositories()->update(['ruleset_id' => null]);
        $ruleset->delete();

        return response()->json(null, 204);
    }
}
