<?php

declare(strict_types=1);

namespace Racin\Storage;

use InvalidArgumentException;

/**
 * Disks from config/filesystems.php.
 */
final class StorageManager
{
    /** @var array<string, Storage> */
    private array $disks = [];

    /**
     * @param array{default?: string, disks?: array<string, array{root: string, url?: string|null}>} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function disk(?string $name = null): Storage
    {
        $name ??= (string) ($this->config['default'] ?? 'public');

        if (!isset($this->disks[$name])) {
            $disk = $this->config['disks'][$name] ?? throw new InvalidArgumentException("Disk [{$name}] is not configured.");
            $this->disks[$name] = new Storage((string) $disk['root'], isset($disk['url']) ? (string) $disk['url'] : null);
        }

        return $this->disks[$name];
    }
}
