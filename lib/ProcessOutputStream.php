<?php

namespace Amp\Process;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Promise;
use function Amp\async;
use function Amp\await;
use function Revolt\EventLoop\defer;

final class ProcessOutputStream implements OutputStream
{
    /** @var \SplQueue */
    private \SplQueue $queuedWrites;

    /** @var bool */
    private bool $shouldClose = false;

    private ResourceOutputStream $resourceStream;

    private ?\Throwable $error = null;

    public function __construct(Promise $resourceStreamPromise)
    {
        $this->queuedWrites = new \SplQueue;

        defer(function () use ($resourceStreamPromise): void {
            try {
                $resourceStream = await($resourceStreamPromise);

                while (!$this->queuedWrites->isEmpty()) {
                    /**
                     * @var string        $data
                     * @var \Amp\Deferred $deferred
                     */
                    [$data, $deferred] = $this->queuedWrites->shift();
                    $deferred->resolve(async(fn () => $resourceStream->write($data)));
                }

                $this->resourceStream = $resourceStream;

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                }
            } catch (\Throwable $exception) {
                $this->error = new StreamException("Failed to launch process", 0, $exception);

                while (!$this->queuedWrites->isEmpty()) {
                    [, $deferred] = $this->queuedWrites->shift();
                    $deferred->fail($this->error);
                }
            }
        });
    }

    /** @inheritdoc */
    public function write(string $data): void
    {
        if (isset($this->resourceStream)) {
            $this->resourceStream->write($data);
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $deferred = new Deferred;
        $this->queuedWrites->push([$data, $deferred]);

        await($deferred->promise());
    }

    /** @inheritdoc */
    public function end(string $finalData = ""): void
    {
        if (isset($this->resourceStream)) {
            $this->resourceStream->end($finalData);
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $deferred = new Deferred;
        $this->queuedWrites->push([$finalData, $deferred]);

        $this->shouldClose = true;

        await($deferred->promise());
    }

    public function close(): void
    {
        $this->shouldClose = true;

        if (isset($this->resourceStream)) {
            $this->resourceStream->close();
        } elseif (!$this->queuedWrites->isEmpty()) {
            $error = new ClosedException("Stream closed.");
            do {
                [, $deferred] = $this->queuedWrites->shift();
                $deferred->fail($error);
            } while (!$this->queuedWrites->isEmpty());
        }
    }
}
