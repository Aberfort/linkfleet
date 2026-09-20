<?php

namespace App\Http\Controllers;

use App\Models\Link;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class QrCodeController extends Controller
{
    /**
     * Public on purpose: the QR just encodes the short URL, which whoever
     * has the code can already visit - so there's nothing to leak, and
     * being public means it can be dropped straight into an <img src>.
     * It encodes the branded address when the site has a verified domain,
     * so a printed code keeps working the same way the copied link does.
     */
    public function show(string $code): Response
    {
        $link = Link::where('short_code', $code)->first();

        if (! $link) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $result = (new Builder(
            writer: new SvgWriter,
            data: $link->short_url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 8,
        ))->build();

        return response($result->getString(), HttpResponse::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
