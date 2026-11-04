<?php
declare(strict_types=1);

namespace KT\Http;

final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $extra = []
    ) {
        parent::__construct($errorCode, $status);
    }

    public function toResponse(): Response
    {
        return Response::json(['error' => $this->errorCode] + $this->extra, $this->status);
    }
}
