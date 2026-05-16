# AmphpBundle

Replaces PHP-FPM with a long-running async HTTP server for Symfony. All I/O is
non-blocking and fiber-based. No more PHP-FPM process overhead.

## Features

- **Async HTTP server** — built on [amphp/http-server](https://github.com/amphp/http-server)
- **Async Doctrine** — non-blocking MySQL/PostgreSQL driver with connection pooling
- **WebSocket server** — attribute-driven endpoints, no Symfony boot for handshake
- **SSE streaming** — server-sent events via `SseResponse`
- **Generator-based streaming** — async `StreamedResponse` with `yield`
- **Hot reload** — `--dev --watch` restarts on file changes (requires `fswatch`)
- **Cluster mode** — multi-worker with process supervision and graceful restart
- **Prometheus metrics** — `/metrics` endpoint with `MetricCollectorInterface`
- **Liveness / Readiness probes** — `/healthz` (no Symfony boot), `/readyz` (full stack)
- **Async Redis** — cache, session handler, messenger transport
- **Async Mailer** — SMTP + HTTP via `amphp/http-client`

## Quick Start

### 1. Install

```bash
composer require app/amphp-bundle "@dev"
```

Register the bundle in `config/bundles.php`:

```php
return [
    PRSW\AmphpBundle\AmphpBundle::class => ['all' => true],
];
```

### 2. Start the Server

```bash
# Production — cluster mode with process supervision
php bin/console amphp:start --workers=4

# Development — single worker, hot reload
php bin/console amphp:start --dev --watch
```

Press Ctrl+C to stop.

### 3. Add a WebSocket Endpoint

Create a class with the `#[WebsocketEndpoint]` attribute — no YAML needed:

```php
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\WebsocketClient;
use PRSW\AmphpBundle\Websocket\Attribute\WebsocketEndpoint;

#[WebsocketEndpoint(path: '/chat')]
class ChatHandler implements WebsocketClientHandler
{
    public function handleClient(
        WebsocketClient $client,
        \Amp\Http\Server\Request $request,
        \Amp\Http\Server\Response $response,
    ): void {
        $gateway = new WebsocketClientGateway();
        $gateway->addClient($client);
        $gateway->broadcastText('User joined');

        foreach ($client as $message) {
            $gateway->broadcastText($message->buffer());
        }
    }
}
```

The bundle auto-discovers `#[WebsocketEndpoint]` handlers at startup.

## CLI Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--workers` | `-w` | Number of worker processes | CPU count |
| `--max-requests` | `-r` | Requests per worker before restart | `1000` |
| `--host` | | Bind address | `127.0.0.1` |
| `--port` | `-p` | Bind port | `8080` |
| `--shutdown-timeout` | `-t` | Drain timeout (seconds) | `1` |
| `--dev` | | Single worker, `--env=dev`, `--debug=true` | |
| `--watch` | | File watcher + auto-restart (needs `--dev`) | |

## Configuration

```yaml
# config/packages/amphp.yaml
amphp:
    host: '127.0.0.1'
    port: 8080
    workers: 0                        # 0 = auto-detect CPU count
    max_requests: 1000                # 0 = never restart
    shutdown_timeout: 5               # drain timeout before force-stop
    gc_interval: 30                   # PHP cycle collector (0 = off)

    dbal:
        max_connections: 100          # connection pool size
        idle_timeout: 60              # close idle connections after N seconds
        ping_interval: 30             # keepalive ping interval (0 = every request)

    static_files:
        enabled: true
        public_dir: null              # defaults to %kernel.project_dir%/public
        indexes: ['index.html', 'index.htm']
        expires_period: 604800

    tls:
        enabled: false
        cert_file: null
        key_file: null
        # See docs/tls.md for full TLS config

    readiness:
        enabled: true
        check_db: true

    websocket:
        enabled: true                 # set false to disable WebSocket entirely
```

> DBAL pool settings from `amphp.dbal` are automatically wired into Doctrine's
> `driverOptions`. Keep `max_connections` below your MySQL server's limit
> (`SHOW VARIABLES LIKE 'max_connections'`; default 151).

## Per-Request Cleanup (Priority Reset System)

After each request, the bundle runs a chain of resetters to release resources and
prevent state leaking between requests. Built-in resetters handle the Doctrine
connection pool and debug logger; you can add your own via `PriorityResetInterface`.

### How It Works

1. `SymfonyRequestHandler` calls `RequestResetter::reset()` in the `finally` block.
2. `RequestResetter` sorts all tagged services by priority (highest first) and
   calls `reset()` on each.
3. Built-in priorities:
   - `DatabaseResetter` — **100** (releases pooled DB connection + FiberLocal entries)
   - User-defined — **-255 to 255** (your custom resetters)
   - `DebugLoggerResetter` — **-255** (clears debug logs)

### Adding a Custom Resetter

Create a class implementing `PriorityResetInterface`:

```php
use PRSW\AmphpBundle\Runtime\Bridge\PriorityResetInterface;
use Symfony\Contracts\Service\ResetInterface;

final class MyConnectionResetter implements PriorityResetInterface
{
    public function __construct(
        private readonly MyConnectionPool $pool,
    ) {}

    public function reset(): void
    {
        $this->pool->releaseConnections();
    }

    public function getPriority(): int
    {
        // Run after DatabaseResetter (100) but before DebugLoggerResetter (-255)
        return 50;
    }
}
```

That's it — no manual tagging or service registration needed. The bundle's
`BootstrapIntegrationPass` auto-configures any service implementing
`PriorityResetInterface` with the `amphp.resetter` tag, and `RequestResetter`
discovers it automatically via the tagged iterator.

### Execution Order

Higher priority numbers run first. Use the built-in constants as anchor points:

| Priority | Resetter | When it runs |
|----------|----------|-------------|
| 100 | `DatabaseResetter` | Release pooled connection, clear FiberLocal |
| 50 | User example | Custom pool release |
| 0 | User example | General-purpose cleanup |
| -255 | `DebugLoggerResetter` | Clear debug logs (last) |

### Real-World Use Cases

- Release custom connection pools (Redis, RabbitMQ, gRPC)
- Clear in-memory caches or request-scoped state
- Reset rate-limit counters or circuit breakers
- Close temporary file handles or streams

## Built-in Endpoints

| Path | Type | Description |
|------|------|-------------|
| `/healthz` | AMPHP middleware | Returns `{"status":"alive"}` — no Symfony boot |
| `/readyz` | Symfony route | Full-stack readiness check |
| `/metrics` | Symfony route | Prometheus metrics |
| user-defined | WebSocket | `#[WebsocketEndpoint]` handlers |

## Benchmarks

Results with `wrk -t1 -c100 -d30s`, 1 worker, APP_ENV=prod, APP_DEBUG=0:

| Endpoint | Requests/s | Avg Latency | Description |
|----------|-----------|-------------|-------------|
| `/twig-test` | **1,446** | 69 ms | Twig template render, no DB |
| `/products` | **392** | 254 ms | Doctrine DBAL query + Twig render (15 rows) |

Compared to PHP-FPM with the same Symfony app, the async server eliminates
the PHP-FPM process spawn overhead and connection pool contention, yielding
higher throughput under concurrent load.

## SSE Streaming

```php
use PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation\SseEvent;
use PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation\SseResponse;

#[Route('/events')]
public function stream(): SseResponse
{
    return new SseResponse(function () {
        while (true) {
            yield new SseEvent(data: ['time' => time()], event: 'tick');
            \Amp\delay(1);
        }
    });
}
```

## Generator-Based Streaming

```php
#[Route('/stream')]
public function stream(): StreamedResponse
{
    return new StreamedResponse(function () {
        $handle = yield \Amp\File\open('/path/to/file', 'r');
        while (null !== $chunk = yield $handle->read()) {
            yield $chunk;
        }
    });
}
```

## Prometheus Metrics

Implement `MetricCollectorInterface` (autotagged `amphp.metric_collector`):

```php
use PRSW\AmphpBundle\Metrics\Metric;
use PRSW\AmphpBundle\Metrics\MetricCollectorInterface;

final class OrderMetrics implements MetricCollectorInterface
{
    public function collect(): array
    {
        return [
            new Metric('orders_total', 42.0, 'Total orders', 'counter', ['status' => 'completed']),
        ];
    }
}
```

## Optional Integrations

| Feature | Packages |
|---------|----------|
| Async Doctrine (MySQL/PostgreSQL) | `amphp/mysql` + `doctrine/orm` |
| Async Redis cache / sessions / messenger | `amphp/redis` |
| Async Mailer (SMTP + HTTP) | `symfony/mailer` + `amphp/http-client` |
| WebSocket server | included with the bundle |
| Hot reload (`--watch`) | `fswatch` (system package) |
