<?php

namespace Tenma\Psr;

use Closure;
use Psr\Http\Message\{ResponseInterface, ServerRequestFactoryInterface, ServerRequestInterface, StreamFactoryInterface, UploadedFileFactoryInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Http\{Request, Response, Stream, StreamedResponse, UploadedFile, WebSocketResponse};

/**
 * Runs a PSR-15 app under Tenma: `new \Http\Server(3000, Psr::handler($app, new Psr17Factory()))->start()`. Every request
 * becomes a PSR-7 ServerRequest built with the PSR-17 factory you pass (nyholm/psr7, guzzlehttp/psr7, ...);
 * the app's response goes back as Tenma's. A WebSocket or a stream goes out through Upgrade.
 *
 * One process serves many requests at once, each in a Fiber of its own: an app that keeps request state in
 * globals or statics needs `--workers N` with one request per worker at a time, or a fix.
 */
final class Psr
{
    /** A body up to this size goes out in one piece; a bigger one, or one of unknown size, is streamed. */
    private const BUFFER_LIMIT = 1048576;

    private const CHUNK = 65536;

    /** They describe the body a stream replaces. */
    private const BODY_HEADERS = ['content-length' => true, 'content-type' => true, 'content-encoding' => true];

    /** @return Closure(Request): (Response|StreamedResponse|WebSocketResponse) Tenma's request handler. */
    public static function handler(RequestHandlerInterface $app, ServerRequestFactoryInterface&StreamFactoryInterface&UploadedFileFactoryInterface $factory): Closure
    {
        return static fn (Request $request): Response|StreamedResponse|WebSocketResponse => self::toTenma($app->handle(self::toPsr($request, $factory)));
    }

    public static function toPsr(Request $request, ServerRequestFactoryInterface&StreamFactoryInterface&UploadedFileFactoryInterface $factory): ServerRequestInterface
    {
        $queryString = explode('?', $request->uri, 2)[1] ?? '';
        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->uri,
            'QUERY_STRING' => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => $request->remoteAddress,
            'REMOTE_PORT' => $request->remotePort,
            'SERVER_ADDR' => $request->serverAddress,
            'SERVER_PORT' => $request->serverPort,
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        // Over Tenma's own TLS (--tls-cert); behind a proxy that ends TLS it is plain http, X-Forwarded-Proto isn't trusted here
        if ($request->https) {
            $server['HTTPS'] = 'on';
        }
        $scheme = $request->https ? 'https' : 'http';
        $uri = "$scheme://" . self::authority($request) . $request->path . ($queryString !== '' ? "?$queryString" : '');
        $psr = $factory->createServerRequest($request->method, $uri, $server)
            ->withQueryParams($request->query)
            ->withCookieParams($request->cookies())
            ->withBody($factory->createStream($request->body))
            ->withUploadedFiles(self::files($request->files(), $factory));
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        // As PSR-7 asks: a form's fields as the parsed body; JSON and the rest are left to the app
        if (preg_match('#^(application/x-www-form-urlencoded|multipart/form-data)\s*(;|$)#i', $request->header('content-type') ?? '') === 1) {
            $psr = $psr->withParsedBody($request->form());
        }
        return $psr;
    }

    public static function toTenma(ResponseInterface $response): Response|StreamedResponse|WebSocketResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = count($values) === 1 ? $values[0] : array_values($values);
        }
        if ($response instanceof Upgrade) {
            $upgrade = $response->upgrade;
            if ($upgrade instanceof WebSocketResponse) {
                return $upgrade;
            }
            // Headers the app's middlewares added (CORS, security headers) go along; the stream's own win
            return new StreamedResponse($upgrade->writer, $upgrade->status, [...array_diff_key($headers, self::BODY_HEADERS), ...$upgrade->headers]);
        }
        $body = $response->getBody();
        $size = $body->getSize();
        if ($size !== null && $size <= self::BUFFER_LIMIT) {
            return new Response((string) $body, $response->getStatusCode(), $headers);
        }
        // Sent as it is read, in chunks: Tenma frames it, a length the app set would contradict that
        unset($headers['content-length']);
        return new StreamedResponse(static function (Stream $stream) use ($body): void {
            try {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (!$body->eof() && ($chunk = $body->read(self::CHUNK)) !== '') {
                    $stream->write($chunk);
                }
            } finally {
                $body->close();
            }
        }, $response->getStatusCode(), $headers);
    }

    /** The Host header if it is one; otherwise the address the connection arrived on. */
    private static function authority(Request $request): string
    {
        $host = $request->header('host') ?? '';
        if (preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?\z/', $host) === 1) {
            return $host;
        }
        $address = str_contains($request->serverAddress, ':') ? "[$request->serverAddress]" : $request->serverAddress;
        return "$address:$request->serverPort";
    }

    /**
     * @param array<array-key, mixed> $files Tenma's UploadedFile, nested as the field names say
     * @return array<array-key, mixed>
     */
    private static function files(array $files, StreamFactoryInterface&UploadedFileFactoryInterface $factory): array
    {
        $psr = [];
        foreach ($files as $name => $file) {
            if ($file instanceof UploadedFile) {
                $psr[$name] = $factory->createUploadedFile($factory->createStream($file->contents()), $file->size(), UPLOAD_ERR_OK, $file->name, $file->type);
            } elseif (is_array($file)) {
                $psr[$name] = self::files($file, $factory);
            }
        }
        return $psr;
    }
}
