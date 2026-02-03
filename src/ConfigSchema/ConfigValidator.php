<?php

namespace App\ConfigSchema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class ConfigValidator
{
    private Validator $validator;

    public function __construct(
        private readonly string $projectDir,
    ) {
        $this->validator = new Validator();
    }

    /**
     * @param array<string, mixed> $collection
     */
    public function assertValidLatest(array $collection): void
    {
        $schemaPath = sprintf(
            '%s/schemas/api-config/v%d/config.schema.json',
            rtrim($this->projectDir, '/'),
            SchemaVersion::LATEST,
        );

        try {
            $schemaJson = file_get_contents($schemaPath);
        } catch (\Throwable $e) {
            throw new ConfigSchemaException('Unable to read schema file.', [], $e);
        }

        if ($schemaJson === false) {
            throw new ConfigSchemaException('Unable to read schema file.');
        }

        $schema = json_decode($schemaJson);

        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigSchemaException('Schema file is not valid JSON: '.json_last_error_msg());
        }

        $data = json_decode(json_encode($collection, JSON_THROW_ON_ERROR));

        $result = $this->validator->validate($data, $schema);

        if ($result->isValid()) {
            return;
        }

        $formatter = new ErrorFormatter();
        $errors = $formatter->format($result->error());

        throw new ConfigSchemaException('Config JSON failed schema validation.', $errors);
    }
}
