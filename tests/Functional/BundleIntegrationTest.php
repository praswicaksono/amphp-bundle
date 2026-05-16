<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Tests\Functional;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use PHPUnit\Framework\TestCase;
use PRSW\AmphpBundle\Runtime\Server\HandlerChainFactory;
use PRSW\AmphpBundle\Runtime\Server\ServerConfig;

use function Amp\async;
use function Amp\delay;

class BundleIntegrationTest extends TestCase
{
    private TestKernel $kernel;

    private SocketHttpServer $httpServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kernel = new TestKernel('test', true);
        $this->kernel->boot();

        $container = $this->kernel->getContainer();
        $logger = $container->get('logger');
        $resetter = $container->get('amphp.request_resetter');

        $errorRenderer = $container->has('error_renderer')
            ? $container->get('error_renderer')
            : null;

        $config = new ServerConfig(
            devMode: true,
            watchMode: false,
            projectDir: $this->kernel->getProjectDir(),
            host: '127.0.0.1',
            port: 0,
            maxRequests: 0,
            workers: 1,
            shutdownTimeout: 1.0,
            tlsEnabled: false,
            tlsMinVersion: 'TLSv1.2',
            tlsSecurityLevel: 2,
            tlsAlpnProtocols: [],
            tlsVerifyPeer: true,
            tlsVerifyPeerName: true,
            tlsVerifyDepth: 10,
            tlsCapturePeer: false,
            tlsSniCerts: [],
            staticFilesEnabled: false,
            staticFilesPublicDir: $this->kernel->getProjectDir() . '/public',
            staticFilesIndexes: [],
            staticFilesExpiresPeriod: 0,
            requestTimeout: 30,
            headerTimeout: 10,
            bodyTimeout: 30,
            maxBodySize: 10 * 1024 * 1024,
            websocketEnabled: false,
            websocketEndpoints: [],
        );

        $this->httpServer = SocketHttpServer::createForDirectAccess($logger);

        $handlerFactory = new HandlerChainFactory();
        $handler = $handlerFactory->build(
            config: $config,
            server: $this->httpServer,
            logger: $logger,
            kernel: $this->kernel,
            errorRenderer: $errorRenderer,
            resetter: $resetter,
            container: $container,
            onShutdownRequested: null,
        );

        $this->httpServer->expose(new InternetAddress('127.0.0.1', 0));
        $this->httpServer->start($handler, new DefaultErrorHandler());
    }

    protected function tearDown(): void
    {
        $this->httpServer->stop();
        $this->kernel->shutdown();

        parent::tearDown();
    }

    private function getAuthority(): string
    {
        $binding = $this->httpServer->getServers()[0]
            ?? self::fail('No servers created by HTTP server');

        return 'http://' . $binding->getAddress()->toString();
    }

    public function testHealthzReturns200(): void
    {
        $httpClient = HttpClientBuilder::buildDefault();

        $future = async(function () use ($httpClient): array {
            $response = $httpClient->request(
                new ClientRequest($this->getAuthority() . '/healthz'),
            );

            return [$response->getStatus(), $response->getReason(), $response->getBody()->buffer()];
        });

        [$status, $reason, $body] = $future->await();

        self::assertSame(200, $status);
        self::assertSame('OK', $reason);
    }

    public function testNonExistentRouteReturns404(): void
    {
        $httpClient = HttpClientBuilder::buildDefault();

        $future = async(function () use ($httpClient): array {
            $response = $httpClient->request(
                new ClientRequest($this->getAuthority() . '/does-not-exist'),
            );

            return [$response->getStatus(), $response->getReason()];
        });

        [$status, $reason] = $future->await();

        self::assertSame(404, $status);
    }
}
