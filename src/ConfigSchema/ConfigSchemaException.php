<?php

namespace App\ConfigSchema;

final class ConfigSchemaException extends \RuntimeException
{
    /** @var array<int, array<string, mixed>> */
    private array $errors;

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    public function __construct(string $message, array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errors = $errors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
