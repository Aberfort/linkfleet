<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Site;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    /** Every site in every workspace the caller belongs to. */
    public function index(Request $request)
    {
        $roles = $request->user()->workspaceRoles();

        return Site::query()
            ->whereIn('workspace_id', array_keys($roles))
            ->with('workspace:id,name')
            ->withCount('links')
            ->latest()
            ->get()
            ->each(fn (Site $site) => $site->setAttribute('role', $roles[$site->workspace_id]->value));
    }

    public function store(StoreSiteRequest $request)
    {
        $site = new Site($request->validated());
        $site->workspace_id = $request->validated('workspace_id');
        $site->save();

        // refresh(): the row's defaults (conversion_tracking) exist only in the
        // database until it is read back, and the response should match every
        // other endpoint's.
        return response()->json($this->withRole($request, $site->refresh()->load('workspace:id,name')), 201);
    }

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        return $this->withRole($request, $site->load('workspace:id,name')->loadCount('links'));
    }

    public function update(UpdateSiteRequest $request, Site $site)
    {
        $site->update($request->validated());

        return $this->withRole($request, $site->load('workspace:id,name')->loadCount('links'));
    }

    public function destroy(Site $site)
    {
        $this->authorize('delete', $site);

        $site->delete();

        return response()->json(null, 204);
    }

    /** What the caller may do with this site: the UI shows or hides controls from it. */
    private function withRole(Request $request, Site $site): Site
    {
        return $site->setAttribute('role', $request->user()->roleIn($site->workspace_id)->value);
    }
}
