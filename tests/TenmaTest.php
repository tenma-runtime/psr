<?php

namespace Tenma\Psr\Tests;

use PHPUnit\Framework\TestCase;

/** The bridge under a real Tenma (tests/fixtures/app.php); skipped without it. TENMA_BIN points at the binary when it isn't on the PATH. */
final class TenmaTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $tenma = getenv('TENMA_BIN') ?: trim((string) shell_exec('command -v tenma'));
        if ($tenma === '' || !is_executable($tenma)) {
            self::markTestSkipped('Tenma not found: set TENMA_BIN or put tenma on the PATH');
        }
        self::$port = random_int(20000, 40000);
        $server = proc_open([$tenma, '--workers', '1', __DIR__ . '/fixtures/app.php', (string) self::$port], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertNotFalse($server);
        self::$server = $server;
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', self::$port) === false; $i++) {
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed>|string $body
     * @return array{string, string} head, body
     */
    private function request(string $path, array $headers = [], array|string|null $body = null): array
    {
        $curl = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = (string) curl_exec($curl);
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return [substr($response, 0, $size), substr($response, $size)];
    }

    public function testARequestArrivesAsAServerRequest(): void
    {
        [, $body] = $this->request('/echo?a=1&b[]=2', ['X-Test: yes', 'Cookie: c=3'], ['field' => 'v', 'upload' => new \CURLStringFile('data', 'a.txt', 'text/plain')]);
        $echo = json_decode($body, true);

        $this->assertSame('POST', $echo['method']);
        $this->assertSame('http://127.0.0.1:' . self::$port . '/echo?a=1&b%5B%5D=2', $echo['uri']);
        $this->assertSame(['a' => '1', 'b' => ['2']], $echo['query']);
        $this->assertSame(['c' => '3'], $echo['cookies']);
        $this->assertSame('yes', $echo['header']);
        $this->assertSame(['field' => 'v'], $echo['parsed']);
        $this->assertSame(['upload' => ['a.txt', 'text/plain', 4, 'data']], $echo['files']);
        $this->assertSame('127.0.0.1', $echo['remote']);
    }

    public function testAJsonBodyStaysRawForTheApp(): void
    {
        [, $body] = $this->request('/echo', ['Content-Type: application/json'], '{"x":1}');
        $echo = json_decode($body, true);

        $this->assertNull($echo['parsed']);
        $this->assertSame('{"x":1}', $echo['body']);
    }

    public function testABigBodyIsStreamedAndSeveralCookiesStaySeparate(): void
    {
        [, $body] = $this->request('/big');
        $this->assertSame(3 * 1024 * 1024, strlen($body));

        [$head] = $this->request('/cookies');
        $this->assertMatchesRegularExpression('/^set-cookie: a=1\r$/mi', $head);
        $this->assertMatchesRegularExpression('/^set-cookie: b=2\r$/mi', $head);
    }

    public function testAnEventStreamKeepsTheHeadersOfTheAppsMiddlewares(): void
    {
        [$head, $body] = $this->request('/events');

        $this->assertMatchesRegularExpression('/^content-type: text\/event-stream\r$/mi', $head);
        $this->assertMatchesRegularExpression('/^x-app: yes\r$/mi', $head);
        $this->assertSame("data: hello\n\n", $body);
    }

    public function testAWebSocketTakesOverTheConnection(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, timeout: 5);
        $this->assertNotFalse($socket);
        stream_set_timeout($socket, 5);
        fwrite($socket, "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $head = '';
        while (!str_contains($head, "\r\n\r\n") && ($line = fgets($socket)) !== false) {
            $head .= $line;
        }
        $this->assertStringStartsWith('HTTP/1.1 101', $head);

        // One masked text frame up (client frames must be masked), one unmasked frame back
        $mask = random_bytes(4);
        fwrite($socket, "\x81" . chr(0x80 | 2) . $mask . ('hi' ^ substr($mask, 0, 2)));
        $header = (string) fread($socket, 2);
        $reply = (string) fread($socket, ord($header[1]) & 0x7f);
        fclose($socket);

        $this->assertSame('echo: hi', $reply);
    }

    public function testOverTenmasOwnTlsTheRequestIsHttps(): void
    {
        $dir = sys_get_temp_dir() . '/tenma-psr-tls-' . getmypid();
        @mkdir($dir);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $cert);
        openssl_pkey_export($key, $pem);
        file_put_contents("$dir/cert.pem", $cert);
        file_put_contents("$dir/key.pem", $pem);

        $tenma = getenv('TENMA_BIN') ?: trim((string) shell_exec('command -v tenma'));
        $port = self::$port + 1;
        $command = [
            $tenma,
            '--tls-cert', "$dir/cert.pem",
            '--tls-key', "$dir/key.pem",
            __DIR__ . '/fixtures/app.php',
            (string) $port,
        ];
        $server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertNotFalse($server);

        $curl = curl_init("https://127.0.0.1:$port/echo");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        for ($i = 0; $i < 100 && ($body = curl_exec($curl)) === false; $i++) {
            usleep(50_000);
        }
        // An open keep-alive connection would hold Tenma's shutdown for --stop-timeout
        unset($curl);
        proc_terminate($server);
        proc_close($server);
        array_map(unlink(...), glob("$dir/*.pem") ?: []);
        rmdir($dir);

        $echo = json_decode((string) $body, true);
        $this->assertSame("https://127.0.0.1:$port/echo", $echo['uri']);
        $this->assertSame('on', $echo['https']);
    }
}
