<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ScannerManifest implements JsonSerializable
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $version
     * @param list<non-empty-string> $languages
     * @param list<non-empty-string> $fileExtensions
     * @param list<non-empty-string> $capabilities
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $protocolVersion,
        public string $outputSchemaVersion,
        public array $languages,
        public array $fileExtensions,
        public array $capabilities,
    ) {
        if ($id === '' || $version === '' || $protocolVersion === '' || $outputSchemaVersion === '') {
            throw new InvalidArgumentException('Scanner identity and version fields must not be empty.');
        }

        if ($languages === []) {
            throw new InvalidArgumentException('A scanner must support at least one language.');
        }

        self::assertNonEmptyStrings($languages, 'languages');
        self::assertNonEmptyStrings($fileExtensions, 'fileExtensions');
        self::assertNonEmptyStrings($capabilities, 'capabilities');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'version', 'protocol_version', 'output_schema_version'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidArgumentException(sprintf('Manifest field "%s" must be a string.', $field));
            }
        }

        foreach (['languages', 'file_extensions', 'capabilities'] as $field) {
            if (!isset($data[$field]) || !is_array($data[$field]) || !array_is_list($data[$field])) {
                throw new InvalidArgumentException(sprintf('Manifest field "%s" must be a list.', $field));
            }
        }

        /** @var list<string> $languages */
        $languages = $data['languages'];
        /** @var list<string> $fileExtensions */
        $fileExtensions = $data['file_extensions'];
        /** @var list<string> $capabilities */
        $capabilities = $data['capabilities'];

        return new self(
            $data['id'],
            $data['version'],
            $data['protocol_version'],
            $data['output_schema_version'],
            $languages,
            $fileExtensions,
            $capabilities,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'protocol_version' => $this->protocolVersion,
            'output_schema_version' => $this->outputSchemaVersion,
            'languages' => $this->languages,
            'file_extensions' => $this->fileExtensions,
            'capabilities' => $this->capabilities,
        ];
    }

    /** @param list<mixed> $values */
    private static function assertNonEmptyStrings(array $values, string $field): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('Manifest field "%s" must contain non-empty strings.', $field));
            }
        }
    }
}
