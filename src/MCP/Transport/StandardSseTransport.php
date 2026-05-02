<?php

namespace App\MCP\Transport;

use Mcp\Server\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A custom transport that allows us to feed a single POST request's JSON into the SDK
 * and capture the newly generated session ID for the initialize request.
 */
class StandardSseTransport implements TransportInterface
{
    /** @var callable */
    private $messageListener;
    /** @var callable */
    private $sessionEndListener;
    
    public ?Uuid $newSessionId = null;

    public function __construct(
        private string $input,
        private ?Uuid $sessionId = null
    ) {
    }

    public function initialize(): void
    {
    }

    public function listen(): mixed
    {
        if (is_callable($this->messageListener)) {
            // Feed the incoming POST request body to the SDK Protocol
            ($this->messageListener)($this, $this->input, $this->sessionId);
        }
        return null;
    }

    public function send(string $data, array $context): void
    {
        // This is only called if a response is NOT queued (e.g., immediate error).
        // Since we are handling this in a POST request, we can just echo it and exit.
        header('Content-Type: application/json');
        echo $data;
        exit;
    }

    public function close(): void
    {
    }

    public function setMessageListener(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function setSessionEndListener(callable $listener): void
    {
        $this->sessionEndListener = $listener;
    }

    public function setOutgoingMessagesProvider(callable $provider): void
    {
    }

    public function setPendingRequestsProvider(callable $provider): void
    {
    }

    public function setResponseFinder(callable $finder): void
    {
    }

    public function setFiberYieldHandler(callable $handler): void
    {
    }

    public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void
    {
        // For synchronous PHP without long-running async fibers, we just let the fiber run.
        // If a handler suspends, it means we would need to poll it. 
        // For our simple tools, they execute synchronously.
    }

    public function setSessionId(Uuid $sessionId): void
    {
        $this->newSessionId = $sessionId;
    }
}
