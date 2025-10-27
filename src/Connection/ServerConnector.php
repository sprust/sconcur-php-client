<?php

namespace SConcur\Connection;

use Psr\Log\LoggerInterface;
use RuntimeException;
use SConcur\Contracts\ServerConnectorInterface;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\ConnectException;
use SConcur\Exceptions\ContextCheckerException;
use SConcur\Exceptions\NotConnectedException;
use SConcur\Exceptions\ReadException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Exceptions\WriteException;
use SConcur\Exceptions\ResponseIsNotJsonException;
use SConcur\Features\MethodEnum;
use SConcur\SConcur;
use Throwable;

class ServerConnector implements ServerConnectorInterface
{
    protected string $taskKeyPrefix;

    /**
     * @var resource|null
     */
    protected mixed $socket = null;

    protected int $socketBufferSize = 8024;

    protected bool $connected = false;
    protected int $lengthPrefixLength = 4;

    public function __construct(
        protected string $socketAddress,
        protected LoggerInterface $logger,
    ) {
        $this->taskKeyPrefix = (getmypid() ?: throw new RuntimeException('Can not get pid')) . '-';
    }

    public function clone(): ServerConnectorInterface
    {
        $connector = new ServerConnector(
            socketAddress: $this->socketAddress,
            logger: $this->logger,
        );

        $connector->connect();

        return $connector;
    }

    public function connect(): void
    {
        try {
            $socket = @stream_socket_client(
                address: $this->socketAddress,
                error_code: $errno,
                error_message: $errorString,
                timeout: 2.0,
            );
        } catch (Throwable $exception) {
            $this->logger->error(
                (string) new ConnectException(
                    socketAddress: $this->socketAddress,
                    error: $exception->getMessage()
                )
            );

            $this->connected = false;

            return;
        }

        if (!$socket) {
            $this->logger->error(
                (string) new ConnectException(
                    socketAddress: $this->socketAddress,
                    error: $errorString
                )
            );

            $this->connected = false;

            return;
        }

        socket_set_blocking($socket, false);

        $this->socket = $socket;

        $this->connected = true;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }

        $this->socket    = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * @throws WriteException
     * @throws ContextCheckerException
     * @throws NotConnectedException
     */
    public function write(Context $context, MethodEnum $method, string $payload): RunningTaskDto
    {
        if (!$this->connected) {
            throw new NotConnectedException(
                socketAddress: $this->socketAddress
            );
        }

        $taskKey = uniqid(
            prefix: $this->taskKeyPrefix,
            more_entropy: true
        );

        $data = json_encode([
            'fu' => SConcur::getFlowUuid(),
            'md' => $method->value,
            'tk' => $taskKey,
            'pl' => $payload,
        ]);

        $dataLength   = strlen($data);
        $buffer       = pack('N', $dataLength) . $data;
        $bufferLength = $dataLength + $this->lengthPrefixLength;

        $sentBytes  = 0;
        $bufferSize = $this->socketBufferSize;

        while ($sentBytes < $bufferLength) {
            $chunk = substr($buffer, $sentBytes, $bufferSize);

            try {
                $bytes = fwrite(
                    stream: $this->socket,
                    data: $chunk,
                );
            } catch (Throwable $exception) {
                throw new WriteException(
                    message: $exception->getMessage(),
                    previous: $exception,
                );
            }

            if ($bytes === false) {
                $context->check();

                continue;
            }

            $sentBytes += $bytes;
        }

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    /**
     * @throws ContextCheckerException
     * @throws ResponseIsNotJsonException
     * @throws ReadException
     * @throws UnexpectedResponseFormatException
     * @throws NotConnectedException
     */
    public function read(Context $context): ?TaskResultDto
    {
        if (!$this->connected) {
            throw new NotConnectedException(
                socketAddress: $this->socketAddress
            );
        }

        $socket = $this->socket;

        $lengthHeader = '';

        while (strlen($lengthHeader) < 4) {
            try {
                $chunk = fread(
                    stream: $socket,
                    length: 4 - strlen($lengthHeader)
                );
            } catch (Throwable $exception) {
                throw new ReadException(
                    message: $exception->getMessage(),
                );
            }

            if ($chunk === false || $chunk === '') {
                $context->check();

                usleep(100);

                continue;
            }

            $lengthHeader .= $chunk;
        }

        $response   = ""; // TODO: what!?
        $dataLength = unpack('N', $lengthHeader)[1];
        $bufferSize = $this->socketBufferSize;

        while (strlen($response) < $dataLength) {
            $chunk = fread(
                stream: $socket,
                length: min($bufferSize, $dataLength - strlen($response))
            );

            if ($chunk === false || $chunk === '') {
                $context->check();

                continue;
            }

            $response .= $chunk;
        }

        try {
            $responseData = json_decode(
                json: $response,
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new ResponseIsNotJsonException(
                message: $exception->getMessage(),
            );
        }

        try {
            return new TaskResultDto(
                flowUuid: $responseData['fu'],
                method: MethodEnum::from($responseData['md']),
                key: $responseData['tk'],
                isError: $responseData['er'],
                payload: $responseData['pl'],
            );
        } catch (Throwable $exception) {
            throw new UnexpectedResponseFormatException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
