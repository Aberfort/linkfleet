<?php

namespace App\Http\Controllers;

use App\Actions\RecordConversion;
use App\Exceptions\ConversionRejected;
use App\Http\Requests\StoreConversionRequest;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\Site;
use App\Support\ConversionInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ConversionController extends Controller
{
    /** A 1x1 transparent GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Server to server: the way to report money, because the caller is
     * authenticated and the click token never has to reach a browser twice.
     */
    public function store(StoreConversionRequest $request, RecordConversion $record)
    {
        $click = Click::with('link.site')->where('token', $request->validated('click_id'))->first();

        // A click in a workspace you cannot see is answered like one that does
        // not exist - the token is not something to be probed for.
        abort_if($click === null || $request->user()->roleIn($click->link->site->workspace_id) === null, 404);
        // A viewer can see it, but reporting is a write.
        $this->authorize('update', $click->link);

        try {
            [$conversion, $created] = $record->handle(
                $click,
                $request->validated('event'),
                $request->validated('value'),
                $request->validated('currency'),
                (string) $request->validated('external_id'),
                Conversion::SOURCE_SERVER,
            );
        } catch (ConversionRejected $e) {
            throw ValidationException::withMessages(['click_id' => $e->getMessage()]);
        }

        return response()->json([
            ...$this->present($conversion),
            'duplicate' => ! $created,
        ], $created ? 201 : 200);
    }

    /** The latest conversions on a site: what to look at to see whether an integration works. */
    public function index(Site $site)
    {
        $this->authorize('view', $site);

        return Conversion::query()
            ->whereIn('link_id', $site->links()->select('id'))
            ->with('link:id,short_code')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Conversion $conversion) => [
                ...$this->present($conversion),
                'link' => $conversion->link->short_code,
            ]);
    }

    /**
     * From a browser: `<img src=".../lf.gif?click=...&event=purchase">`, or
     * the snippet doing the same. Always answers with the image, whatever
     * happened, so the page it sits on never sees an error and a stranger
     * cannot tell a valid token from an invalid one by the response.
     *
     * Anyone who holds a click token can send this, so what arrives here is
     * recorded as "pixel" and never mistaken for a verified report.
     */
    public function pixel(Request $request, RecordConversion $record): Response
    {
        try {
            $input = ConversionInput::normalise([
                'click_id' => $request->query('click'),
                'event' => $request->query('event'),
                'value' => $request->query('value'),
                'currency' => $request->query('currency'),
                'external_id' => $request->query('id'),
            ]);

            $data = Validator::make($input, ConversionInput::rules())->validate();
            $click = Click::where('token', $data['click_id'])->first();

            if ($click) {
                $record->handle($click, $data['event'], $data['value'] ?? null, $data['currency'] ?? null, (string) ($data['external_id'] ?? ''), Conversion::SOURCE_PIXEL);
            }
        } catch (ValidationException|ConversionRejected) {
            // Nothing to report back, by design.
        } catch (Throwable $e) {
            report($e);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Conversion $conversion): array
    {
        return [
            'id' => $conversion->id,
            'event' => $conversion->event,
            'value' => $conversion->value,
            'currency' => $conversion->currency,
            'external_id' => $conversion->external_id !== '' ? $conversion->external_id : null,
            'source' => $conversion->source,
            'created_at' => $conversion->created_at,
        ];
    }
}
