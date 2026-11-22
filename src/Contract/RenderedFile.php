<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use RuntimeException;

use function fopen;
use function fwrite;
use function is_resource;
use function preg_match;
use function rewind;
use function stream_get_contents;
use function strlen;

/**
 * A rendered export (contract §4.2): file name, content type and the bytes in a `php://temp` stream (memory up to
 * 2 MiB, then a temporary file PHP removes when the stream is freed). Nothing is written to a path the caller must
 * clean up.
 */
final readonly class RenderedFile
{
    /** Spill to a temporary file above this many bytes. */
    public const MEMORY_BYTES = 2097152;

    /** @var resource */
    private mixed $stream;

    /**
     * @param string $filename safe file name with extension (`[a-z0-9-]` plus the extension)
     * @param string $contentType the `Content-Type`
     * @param resource $stream readable stream holding exactly the file
     * @param int $size bytes in the stream
     */
    public function __construct(public string $filename, public string $contentType, mixed $stream, public int $size)
    {
        if (!is_resource($stream)) {
            throw new InvalidReportArgumentException('RenderedFile needs an open stream.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,150}\.[a-z0-9]{2,5}$/D', $filename) !== 1) {
            throw new InvalidReportArgumentException('RenderedFile filename must be a slug with an extension.');
        }
        $this->stream = $stream;
    }

    /**
     * A file from bytes held in memory.
     */
    public static function fromString(string $filename, string $contentType, string $bytes): self
    {
        $stream = self::temporaryStream();
        fwrite($stream, $bytes);

        return new self($filename, $contentType, $stream, strlen($bytes));
    }

    /**
     * A new empty `php://temp` stream to write a file into.
     *
     * @return resource
     */
    public static function temporaryStream(): mixed
    {
        $stream = fopen('php://temp/maxmemory:' . self::MEMORY_BYTES, 'w+');
        if ($stream === false) {
            throw new RuntimeException('Cannot open a php://temp stream.'); // @codeCoverageIgnore
        }

        return $stream;
    }

    /**
     * The stream, rewound to its start.
     *
     * @return resource
     */
    public function stream(): mixed
    {
        rewind($this->stream);

        return $this->stream;
    }

    /**
     * The whole file as a string (mail attachments, tests).
     */
    public function contents(): string
    {
        return (string) stream_get_contents($this->stream(), -1, 0);
    }
}
