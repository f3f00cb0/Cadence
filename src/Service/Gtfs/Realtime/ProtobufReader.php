<?php

declare(strict_types=1);

namespace App\Service\Gtfs\Realtime;

/**
 * Minimal protobuf wire-format reader.
 *
 * Decodes the four wire types we need for GTFS-RT:
 *   - 0 VARINT  (int32, int64, uint32, uint64, bool, enum)
 *   - 1 I64     (fixed64, sfixed64, double)  — skipped only
 *   - 2 LEN     (string, bytes, embedded messages, packed repeated)
 *   - 5 I32     (fixed32, sfixed32, float)   — skipped only
 *
 * Wire reference: https://protobuf.dev/programming-guides/encoding/
 *
 * This is deliberately not a full protobuf implementation — it only knows how
 * to walk a byte stream, decode the fields the GtfsRtParser asks for, and
 * skip everything else. ~120 LOC, no external dependency.
 */
final class ProtobufReader
{
    public const WIRE_VARINT = 0;
    public const WIRE_I64    = 1;
    public const WIRE_LEN    = 2;
    public const WIRE_I32    = 5;

    private int $pos = 0;
    private readonly int $end;

    public function __construct(private readonly string $buffer, ?int $length = null)
    {
        $this->end = $length ?? strlen($buffer);
    }

    public function eof(): bool
    {
        return $this->pos >= $this->end;
    }

    /**
     * Read the next field tag.
     *
     * @return array{0:int,1:int}|null  [fieldNumber, wireType] or null at EOF
     */
    public function readTag(): ?array
    {
        if ($this->eof()) {
            return null;
        }
        $tag = $this->readVarint();
        return [$tag >> 3, $tag & 0x07];
    }

    /**
     * Read a varint as an unsigned int. Caller decodes zig-zag for sint32/64.
     * For sint32/sint64 (zig-zag) use readSint(); for int32/int64 the wire
     * is two-complement encoded as a 64-bit varint — readSignedVarint() handles it.
     */
    public function readVarint(): int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($this->pos >= $this->end) {
                throw new \RuntimeException('Unexpected EOF in varint');
            }
            $byte = ord($this->buffer[$this->pos++]);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new \RuntimeException('Varint too long');
            }
        }
    }

    /**
     * Read a signed varint (proto int32/int64 — sign-extended to 64 bits on the wire).
     * On 64-bit PHP, varints with high bit set come back as negative naturally
     * via the bitwise OR sign extension above. Defensive: re-interpret if needed.
     */
    public function readSignedVarint(): int
    {
        $v = $this->readVarint();
        // On 64-bit PHP, PHP ints are 64-bit signed — a varint of 10 bytes
        // representing a negative int32 will already be the correct negative value.
        return $v;
    }

    public function readBool(): bool
    {
        return $this->readVarint() !== 0;
    }

    /**
     * Read a LEN-delimited slice of bytes (string, bytes, or embedded message).
     */
    public function readBytes(): string
    {
        $len = $this->readVarint();
        if ($len < 0 || $this->pos + $len > $this->end) {
            throw new \RuntimeException('LEN field overflow');
        }
        $out = substr($this->buffer, $this->pos, $len);
        $this->pos += $len;
        return $out;
    }

    /**
     * Skip a field of the given wire type. Use when a tag is not recognized.
     */
    public function skipField(int $wireType): void
    {
        switch ($wireType) {
            case self::WIRE_VARINT:
                $this->readVarint();
                return;
            case self::WIRE_I64:
                $this->pos += 8;
                return;
            case self::WIRE_LEN:
                $len = $this->readVarint();
                $this->pos += $len;
                return;
            case self::WIRE_I32:
                $this->pos += 4;
                return;
            default:
                throw new \RuntimeException("Unsupported wire type $wireType");
        }
        // Bounds check defer to next read.
    }
}
