<?php

namespace App\ConfigSchema;

final class ConfigMigrator
{
    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    public function migrateToLatest(array $collection): array
    {
        $version = (int)($collection['schemaVersion'] ?? 0);

        if ($version === 0) {
            // Treat unknown/legacy as v1 candidate; validator will enforce required fields.
            $collection['schemaVersion'] = SchemaVersion::LATEST;
            return $collection;
        }

        if ($version > SchemaVersion::LATEST) {
            throw new ConfigSchemaException(
                sprintf('Config schemaVersion %d is newer than supported %d.', $version, SchemaVersion::LATEST)
            );
        }

        // v1 is current for now.
        return $collection;
    }
}
