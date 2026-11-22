<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use Nowo\ReportKitBundle\Contract\RenderedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function fclose;
use function fopen;
use function stream_copy_to_stream;

/**
 * Wraps a {@see RenderedFile} in a download response (contract §6.2): `Cache-Control: no-store, private`,
 * `Content-Disposition: attachment` built by `HeaderUtils::makeDisposition()` with an ASCII fallback, `nosniff`, the
 * exact `Content-Length`. Used by the export routes (S2) and available to hosts.
 */
final readonly class RenderedFileResponder
{
    /**
     * A private, never-cached attachment response that streams the file.
     */
    public function respond(RenderedFile $file): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($file): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return; // @codeCoverageIgnore
            }
            stream_copy_to_stream($file->stream(), $out);
            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type'           => $file->contentType,
            'Content-Length'         => (string) $file->size,
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->filename, $file->filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
