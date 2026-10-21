<?php

namespace Tenma\Psr;

use Psr\Http\Message\{ResponseInterface, StreamInterface};
use Http\{StreamedResponse, WebSocketResponse};

/**
 * A response that hands the connection to Tenma: a WebSocket or a stream instead of a body.
 *
 *     return new Upgrade($response, \Http\Response::websocket(function (WebSocket $socket) { ... }));
 *
 * Middlewares may change it like any response: it stays an Upgrade, and for a stream the headers they add
 * (CORS, security headers, cookies) go out with it. Only Psr::handler() understands it; another server sends
 * the wrapped response.
 */
final class Upgrade implements ResponseInterface
{
    public function __construct(
        private readonly ResponseInterface $response,
        public readonly WebSocketResponse|StreamedResponse $upgrade,
    ) {}

    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): self
    {
        return new self($this->response->withProtocolVersion($version), $this->upgrade);
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): self
    {
        return new self($this->response->withHeader($name, $value), $this->upgrade);
    }

    public function withAddedHeader(string $name, $value): self
    {
        return new self($this->response->withAddedHeader($name, $value), $this->upgrade);
    }

    public function withoutHeader(string $name): self
    {
        return new self($this->response->withoutHeader($name), $this->upgrade);
    }

    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function withBody(StreamInterface $body): self
    {
        return new self($this->response->withBody($body), $this->upgrade);
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        return new self($this->response->withStatus($code, $reasonPhrase), $this->upgrade);
    }

    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }
}
