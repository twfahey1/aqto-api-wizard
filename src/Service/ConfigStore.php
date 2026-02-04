<?php

namespace App\Service;

use App\ConfigSchema\ConfigMigrator;
use App\ConfigSchema\ConfigSchemaException;
use App\ConfigSchema\ConfigValidator;
use App\ConfigSchema\SchemaVersion;
use Symfony\Component\Filesystem\Filesystem;

final class ConfigStore
{
    private readonly string $dataFile;

    public function __construct(
        private readonly string $projectDir,
        private readonly ConfigMigrator $migrator,
        private readonly ConfigValidator $validator,
        private readonly Filesystem $filesystem,
    ) {
        $this->dataFile = rtrim($this->projectDir, '/').'/var/data/configs.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCollection(): array
    {
        if (!$this->filesystem->exists($this->dataFile)) {
            return $this->emptyCollection();
        }

        $raw = file_get_contents($this->dataFile);

        if ($raw === false || trim($raw) === '') {
            return $this->emptyCollection();
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ConfigSchemaException('Config store JSON is invalid: '.json_last_error_msg());
        }

        $migrated = $this->migrator->migrateToLatest($decoded);
        $this->validator->assertValidLatest($migrated);

        // Normalize on disk to latest + stable formatting.
        $this->saveCollection($migrated);

        return $migrated;
    }

    /**
     * @param array<string, mixed> $collection
     */
    public function saveCollection(array $collection): void
    {
        $collection['schemaVersion'] = SchemaVersion::LATEST;

        $dir = dirname($this->dataFile);
        $this->filesystem->mkdir($dir);

        $json = json_encode(
            $collection,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new \RuntimeException('Unable to encode config collection JSON: '.json_last_error_msg());
        }

        $this->filesystem->dumpFile($this->dataFile, $json."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCollection(): array
    {
        return [
            'schemaVersion' => SchemaVersion::LATEST,
            'meta' => [
                'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'folders' => [],
            'authProfiles' => [],
            'configs' => [],
        ];
    }
}
