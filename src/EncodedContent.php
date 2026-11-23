<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * Facts about content encoded by FileCodec; everything a caller must persist to
 * decode the stream later.
 */
final readonly class EncodedContent
{
    public function __construct(
        /** sha256 hex of the original (plain) content; its HMAC-SHA256 where the codec has a hash key */
        public string $hash,
        /** Original content size in bytes */
        public int $size,
        /** Bytes written to the target (after compression/encryption) */
        public int $storedSize,
        /** Detected from the leading plain bytes; null when undetectable */
        public ?string $mimeType,
        /** The compression applied: the requested one, or what an AutoCompression chose */
        public Compression $compression,
        public Encryption $encryption,
        /** Key id used for encryption; null when not encrypted */
        public ?string $encryptionKeyId,
        /** base64-encoded secretstream header; null when not encrypted */
        public ?string $encryptionHeader,
        /** Plaintext chunk size of the encrypted framing */
        public int $chunkSize
    ) {}
}
