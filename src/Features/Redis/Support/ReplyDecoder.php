<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Features\Redis\Dto\ErrorReply;
use function msgpack_unpack;

/**
 * A reply on its way back into PHP values.
 *
 * MessagePackTransport::unpack is not used here because it insists on an array, and a
 * Redis reply is just as often a string, an integer or null. What this adds on top of the
 * raw unpack is the error replies a pipeline carries: the core marks them, and they become
 * ErrorReply objects wherever they sit, however deeply nested.
 */
readonly class ReplyDecoder
{
    /** The key the core marks an error reply with. Rust: values::ERROR_MARKER_KEY. */
    protected const string ERROR_MARKER_KEY = '__sconcur_redis_error';

    public static function decode(TaskResultDto $result): mixed
    {
        if ($result->payload === '') {
            return null;
        }

        return static::convert(msgpack_unpack($result->payload));
    }

    /**
     * @return list<mixed>
     */
    public static function decodeList(TaskResultDto $result): array
    {
        $decoded = static::decode($result);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    protected static function convert(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value[static::ERROR_MARKER_KEY])) {
            return new ErrorReply(
                code: (string) ($value['code'] ?? 'ERR'),
                message: (string) ($value['message'] ?? ''),
            );
        }

        $converted = [];

        foreach ($value as $key => $item) {
            $converted[$key] = static::convert($item);
        }

        return $converted;
    }
}
