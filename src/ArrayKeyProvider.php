<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Override;
use SensitiveParameter;

/**
 * Static in-memory key set; suitable when keys are managed by the calling application.
 */
final readonly class ArrayKeyProvider implements KeyProvider
{
    /**
     * @param array<string, string> $keys Raw 32-byte keys indexed by key id
     */
    public function __construct(
        #[SensitiveParameter]
        private array $keys,
        private string $currentKeyId,
    ) {
        if (!isset($this->keys[$this->currentKeyId])) {
            throw EncryptionKeyNotFoundException::byKeyId(keyId: $this->currentKeyId);
        }
    }

    #[Override]
    public function getCurrentKeyId(): string
    {
        return $this->currentKeyId;
    }

    #[Override]
    public function getKey(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw EncryptionKeyNotFoundException::byKeyId(keyId: $keyId);
    }
}
