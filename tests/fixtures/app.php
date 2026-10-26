<?php
// The app TenmaTest runs under Tenma: tenma app.php PORT

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, UploadedFileInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Http\{EventStream, Response, WebSocket};
use Tenma\Psr\{Psr, Upgrade};

require __DIR__ . '/../../vendor/autoload.php';

$factory = new Psr17Factory();

$app = new class ($factory) implements RequestHandlerInterface {
    public function __construct(private Psr17Factory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = match ($request->getUri()->getPath()) {
            '/echo' => $this->json([
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'query' => $request->getQueryParams(),
                'cookies' => $request->getCookieParams(),
                'header' => $request->getHeaderLine('X-Test'),
                'parsed' => $request->getParsedBody(),
                'body' => (string) $request->getBody(),
                'files' => array_map(fn (UploadedFileInterface $file) => [$file->getClientFilename(), $file->getClientMediaType(), $file->getSize(), (string) $file->getStream()], $request->getUploadedFiles()),
                'remote' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
                'https' => $request->getServerParams()['HTTPS'] ?? null,
            ]),
            '/big' => $this->factory->createResponse()->withBody($this->factory->createStream(str_repeat('x', 3 * 1024 * 1024))),
            '/cookies' => $this->factory->createResponse()->withAddedHeader('Set-Cookie', 'a=1')->withAddedHeader('Set-Cookie', 'b=2'),
            '/ws' => new Upgrade($this->factory->createResponse(), Response::websocket(function (WebSocket $socket): void {
                while (($message = $socket->receive()) !== null) {
                    $socket->send("echo: $message");
                }
            })),
            '/events' => new Upgrade($this->factory->createResponse(), Response::events(function (EventStream $events): void {
                $events->send('hello');
            })),
            default => $this->factory->createResponse(404),
        };
        // What a CORS or security-header middleware does: it must survive on an Upgrade
        return $response->withHeader('X-App', 'yes');
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): ResponseInterface
    {
        return $this->factory->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }
};

new \Http\Server((int) ($argv[1] ?? 8124), Psr::handler($app, $factory))->start();
