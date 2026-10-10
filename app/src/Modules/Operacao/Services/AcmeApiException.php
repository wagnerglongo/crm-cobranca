<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use RuntimeException;
use Throwable;

final class AcmeApiException extends RuntimeException
{
    /** @param array<string,mixed> $response */
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly bool $retryable = true,
        private readonly array $response = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /** @return array<string,mixed> */
    public function response(): array
    {
        return $this->response;
    }
}
