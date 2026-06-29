<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use JsonSerializable;
use Override;

/**
 * Supported content encryption algorithms.
 *
 * XChaCha20Poly1305 is the libsodium secretstream construction: a chunked AEAD where every
 * chunk is authenticated and chunk reordering/truncation is detected.
 */
enum Encryption: string implements JsonSerializable
{
    case None = 'none';
    case XChaCha20Poly1305 = 'xchacha20poly1305';

    #[Override]
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
