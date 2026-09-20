<?php

namespace App\Http\Controllers;

use App\Actions\ImportLinksFromCsv;
use App\Http\Requests\ImportLinksRequest;
use App\Http\Requests\StoreLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Models\Link;
use App\Models\Site;
use Illuminate\Support\Facades\Hash;

class LinkController extends Controller
{
    public function index(Site $site)
    {
        $this->authorize('view', $site);

        // Hand each link the site we already have, so short_url doesn't
        // cost two extra queries per row.
        $site->load('customDomain');

        return $site->links()->latest()->get()
            ->each(fn (Link $link) => $link->setRelation('site', $site));
    }

    public function store(StoreLinkRequest $request, Site $site)
    {
        $data = $request->validated();
        $password = $this->pullPassword($data);

        $link = $site->links()->create($data);

        if ($password !== null) {
            $link->forceFill(['password' => $password])->save();
        }

        // Without this the response omits everything the database filled in
        // by default (is_active, clicks_count, expires_at), so the created
        // object wouldn't match the shape every other endpoint returns.
        return response()->json($link->refresh(), 201);
    }

    public function import(ImportLinksRequest $request, Site $site, ImportLinksFromCsv $import)
    {
        return response()->json(
            $import->handle($site, $request->file('file'))
        );
    }

    public function show(Link $link)
    {
        $this->authorize('view', $link);

        return $link;
    }

    public function update(UpdateLinkRequest $request, Link $link)
    {
        $data = $request->validated();
        $hasPasswordKey = array_key_exists('password', $data);
        $password = $this->pullPassword($data);

        $link->update($data);

        // Omitting the key leaves the password alone; sending it empty
        // clears it. That distinction is why this isn't just a fillable.
        if ($hasPasswordKey) {
            $link->forceFill(['password' => $password])->save();
        }

        return $link;
    }

    public function destroy(Link $link)
    {
        $this->authorize('delete', $link);

        $link->delete();

        return response()->json(null, 204);
    }

    /**
     * Removes the plain password from $data and returns it hashed, or null
     * when it was blank (meaning "no password" / "remove the password").
     *
     * @param  array<string, mixed>  $data
     */
    private function pullPassword(array &$data): ?string
    {
        $plain = $data['password'] ?? null;
        unset($data['password']);

        return $plain === null || $plain === '' ? null : Hash::make($plain);
    }

    public function toggle(Link $link)
    {
        $this->authorize('update', $link);

        $link->update(['is_active' => ! $link->is_active]);

        return $link;
    }
}
