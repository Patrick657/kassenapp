<?php
declare(strict_types=1);

namespace Festkasse;

final class ApiException extends \RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
