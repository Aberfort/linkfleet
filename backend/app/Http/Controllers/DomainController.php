<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDomainRequest;
use App\Models\Domain;
use App\Models\Site;
use App\Support\DnsTxtLookup;
use App\Support\DomainProbe;

class DomainController extends Controller
{
    public function show(Site $site)
    {
        $this->authorize('view', $site);

        // Wrapped in a key on purpose: a bare null body comes back as {} once
        // Symfony's JsonResponse is done with it, which reads as "a domain
        // exists" on the client.
        return response()->json(['domain' => $site->customDomain]);
    }

    public function store(StoreDomainRequest $request, Site $site)
    {
        // One domain per site - replacing means the old host stops resolving,
        // which is the owner's call to make.
        $site->customDomain?->delete();

        $domain = $site->customDomain()->create($request->validated());

        return response()->json($domain->refresh(), 201);
    }

    public function verify(Domain $domain, DnsTxtLookup $dns)
    {
        $this->authorize('update', $domain);

        $values = $dns->txtValues($domain->txt_record_name);

        if (! in_array($domain->verification_token, $values, true)) {
            return response()->json([
                'message' => 'TXT-запис не знайдено. DNS може оновлюватись до кількох годин.',
                'domain' => $domain,
            ], 422);
        }

        $domain->forceFill(['verified_at' => now()])->save();

        return response()->json($domain);
    }

    /**
     * Whether the (already verified) domain is actually wired up: DNS
     * pointing at us, then a valid certificate answering on that host.
     * Read-only, so it's allowed for the demo account too.
     */
    public function check(Domain $domain, DomainProbe $probe)
    {
        $this->authorize('view', $domain);

        if (! $domain->is_verified) {
            return response()->json([
                'message' => 'Спершу підтвердіть володіння доменом.',
            ], 409);
        }

        $target = config('features.custom_domain_target');
        $dns = $probe->pointsAt($domain->host, $target);

        return response()->json([
            'target' => $target,
            'dns' => $dns,
            // No point knocking on a host that doesn't lead here yet.
            'https' => $dns && $probe->servesOverHttps($domain->host),
        ]);
    }

    public function destroy(Domain $domain)
    {
        $this->authorize('delete', $domain);

        $domain->delete();

        return response()->noContent();
    }
}
