<?php

namespace Coyote\Certificate;

use Coyote\System\PrivilegedExecutor;
use InvalidArgumentException;

class CertificateStore
{
    public const STORE_PATH = '/mnt/config/certificates';
    public const INDEX_FILE = '/mnt/config/certificates/index.json';
    public const DIR_CA = 'ca';
    public const DIR_SERVER = 'server';
    public const DIR_CLIENT = 'client';
    public const DIR_PRIVATE = 'private';
    public const VALID_TYPES = [
        self::DIR_CA,
        self::DIR_SERVER,
        self::DIR_CLIENT,
        self::DIR_PRIVATE,
    ];

    private array $index = [];
    private PrivilegedExecutor $executor;

    public function __construct()
    {
        $this->executor = new PrivilegedExecutor();
        $this->index = $this->loadIndex();
    }

    public function initialize(): bool
    {
        if (is_dir(self::STORE_PATH) && file_exists(self::INDEX_FILE)) {
            return true;
        }

        $this->remountConfig(true);
        $result = $this->executor->initCertStore();
        $this->remountConfig(false);

        if (!$result['success']) {
            return false;
        }

        $this->index = $this->loadIndex();

        return true;
    }

    public function store(string $type, string $name, string $pemContent, array $metadata = []): string|false
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            return false;
        }

        if (!is_dir(self::STORE_PATH) || !file_exists(self::INDEX_FILE)) {
            if (!$this->initialize()) {
                return false;
            }
        }

        $id = $this->generateId();
        while (isset($this->index[$id])) {
            $id = $this->generateId();
        }

        $subdir = $this->getSubdir($type);
        $filename = $id . '.pem';
        $path = self::STORE_PATH . '/' . $subdir . '/' . $filename;
        $timestamp = time();

        $entry = [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'filename' => $filename,
            'path' => $path,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'metadata' => $metadata,
        ];

        if (!$this->remountConfig(true)) {
            return false;
        }

        $stored = false;
        $remountedReadOnly = false;

        try {
            if (file_put_contents($path, $pemContent) === false) {
                return false;
            }

            chmod($path, 0600);

            $this->index[$id] = $entry;

            if (!$this->saveIndex()) {
                unset($this->index[$id]);
                @unlink($path);
                return false;
            }

            $stored = true;
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        if (!$stored || !$remountedReadOnly) {
            return false;
        }

        return $id;
    }

    public function delete(string $id): bool
    {
        if (!$this->exists($id)) {
            return false;
        }

        $path = $this->getPath($id);
        if ($path === null) {
            return false;
        }

        if (!$this->remountConfig(true)) {
            return false;
        }

        $deleted = false;
        $remountedReadOnly = false;

        try {
            if (file_exists($path) && !unlink($path)) {
                return false;
            }

            unset($this->index[$id]);

            if (!$this->saveIndex()) {
                return false;
            }

            $deleted = true;
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $deleted && $remountedReadOnly;
    }

    public function get(string $id): ?array
    {
        if (!$this->exists($id)) {
            return null;
        }

        return $this->index[$id];
    }

    public function getContent(string $id): ?string
    {
        $path = $this->getPath($id);
        if ($path === null || !file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return $content;
    }

    public function list(string $type = ''): array
    {
        if ($type === '') {
            return array_values($this->index);
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            return [];
        }

        $entries = [];

        foreach ($this->index as $entry) {
            if (($entry['type'] ?? '') === $type) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function listByType(string $type): array
    {
        return $this->list($type);
    }

    public function exists(string $id): bool
    {
        return isset($this->index[$id]);
    }

    public function getPath(string $id): ?string
    {
        if (!$this->exists($id)) {
            return null;
        }

        $entry = $this->index[$id];

        if (isset($entry['path']) && is_string($entry['path'])) {
            return $entry['path'];
        }

        if (!isset($entry['type']) || !is_string($entry['type'])) {
            return null;
        }

        $filename = $entry['filename'] ?? ($id . '.pem');
        if (!is_string($filename)) {
            return null;
        }

        return self::STORE_PATH . '/' . $this->getSubdir($entry['type']) . '/' . $filename;
    }

    public function updateMetadata(string $id, array $metadata): bool
    {
        if (!$this->exists($id)) {
            return false;
        }

        $existingMetadata = $this->index[$id]['metadata'] ?? [];
        if (!is_array($existingMetadata)) {
            $existingMetadata = [];
        }

        $this->index[$id]['metadata'] = array_merge($existingMetadata, $metadata);
        $this->index[$id]['updated_at'] = time();

        if (!$this->remountConfig(true)) {
            return false;
        }

        $updated = false;
        $remountedReadOnly = false;

        try {
            $updated = $this->saveIndex();
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $updated && $remountedReadOnly;
    }

    private function loadIndex(): array
    {
        if (!file_exists(self::INDEX_FILE)) {
            return [];
        }

        $contents = file_get_contents(self::INDEX_FILE);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            $indexedEntries = [];

            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $entryId = $entry['id'] ?? '';
                if (!is_string($entryId) || $entryId === '') {
                    continue;
                }

                $indexedEntries[$entryId] = $entry;
            }

            return $indexedEntries;
        }

        return $data;
    }

    private function saveIndex(): bool
    {
        $json = json_encode($this->index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $tempFile = self::INDEX_FILE . '.tmp';

        if (file_put_contents($tempFile, $json) === false) {
            return false;
        }

        if (!rename($tempFile, self::INDEX_FILE)) {
            @unlink($tempFile);
            return false;
        }

        chmod(self::INDEX_FILE, 0644);

        return true;
    }

    private function remountConfig(bool $writable): bool
    {
        $result = $writable
            ? $this->executor->mountConfigRw()
            : $this->executor->mountConfigRo();

        return $result['success'];
    }

    private function generateId(): string
    {
        return 'cert_' . bin2hex(random_bytes(4));
    }

    private function getSubdir(string $type): string
    {
        return match ($type) {
            self::DIR_CA => self::DIR_CA,
            self::DIR_SERVER => self::DIR_SERVER,
            self::DIR_CLIENT => self::DIR_CLIENT,
            self::DIR_PRIVATE => self::DIR_PRIVATE,
            default => throw new InvalidArgumentException("Invalid certificate type: {$type}"),
        };
    }
}
