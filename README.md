# Tenma PSR

Runs any PSR-15 app under [Tenma](https://tenma.dev): Slim, Mezzio, Waypoint, or a handler of your own.
Requests arrive as PSR-7 `ServerRequestInterface`, built with the PSR-17 factory you choose; the app's
`ResponseInterface` goes back as Tenma's response.

```bash
composer require tenma/psr nyholm/psr7
```

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Tenma\Psr\Psr;

$app = /* any Psr\Http\Server\RequestHandlerInterface, e.g. a Slim app */;

new Http\Server(3000, Psr::handler($app, new Psr17Factory()))->start();
```

```bash
tenma index.php
```

## What arrives

- Method, URI (`http://` + Host header, or the address the connection arrived on), headers, cookies and query.
- The raw body as a stream; for `application/x-www-form-urlencoded` and `multipart/form-data` also the
  fields as the parsed body and the uploads as `UploadedFileInterface`. JSON is left to the app, as PSR-7
  says (Slim's `BodyParsingMiddleware`, for instance).
- Server params as under FPM: `REMOTE_ADDR`, `REMOTE_PORT`, `SERVER_ADDR`, `SERVER_PORT`, `REQUEST_URI`,
  `QUERY_STRING`, `REQUEST_TIME(_FLOAT)`.

TLS ends at a proxy in front of Tenma; the scheme is always `http` and `X-Forwarded-*` headers are not
trusted. Use a middleware that trusts your proxy if you need them.

## What goes out

A body up to 1 MiB goes out in one piece; a bigger one, or one of unknown size, is read and sent in 64 KiB
chunks, so a file stream doesn't end up in memory. Several values of a header (`Set-Cookie`) stay separate.

## WebSockets and streams

PSR-7 has no word for them. `Upgrade` is a response that carries one of Tenma's:

```php
use Http\{EventStream, Response, WebSocket};
use Tenma\Psr\Upgrade;

$app->get('/ws', fn ($request, $response) => new Upgrade($response, Response::websocket(function (WebSocket $socket) {
    while (($message = $socket->receive()) !== null) {
        $socket->send("echo: $message");
    }
})));

$app->get('/events', fn ($request, $response) => new Upgrade($response, Response::events(function (EventStream $events) {
    $events->send('hello');
})));
```

Middlewares can change it like any response and it stays an `Upgrade`. A stream keeps the headers they add
(CORS, security headers, cookies); its own headers and status win. A WebSocket answers with its handshake
only. Another server than Tenma doesn't know `Upgrade` and sends the wrapped response.

## Long-running and concurrent

Tenma keeps the app in memory and serves many requests at once, each in a Fiber of its own. That is fast, and
it changes what an app may do:

- State in globals, statics or singletons is shared by every request in the process, at the same time. An
  app that keeps the current user or request there needs `--workers N` and one request per worker, or a fix.
- `$_GET`, `$_POST`, `$_SESSION`, `header()`, `echo` and `exit` belong to classic PHP; a PSR app uses the
  request and response objects instead.
- Blocking calls (PDO, curl, `file_get_contents`) hold up every request of the worker while they wait. Tenma's
  own APIs (`Http\Client`, `Data\Postgres`, `FileSystem\FileInfo`) wait without blocking.

## Tests

```bash
composer install
composer test    # starts tests/fixtures/app.php under tenma; skipped without it (TENMA_BIN)
composer stan
```

## License

MIT or Apache-2.0, at your option.
