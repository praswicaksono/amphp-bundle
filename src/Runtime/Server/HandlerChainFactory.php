<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use PRSW\AmphpBundle\Runtime\Bridge\LivenessHandler;
use PRSW\AmphpBundle\Runtime\Bridge\RequestResetter;
use PRSW\AmphpBundle\Runtime\Bridge\RequestTimeoutMiddleware;
use PRSW\AmphpBundle\Runtime\Bridge\SymfonyRequestHandler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLogger;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function Amp\Http\Server\Middleware\stackMiddleware;

final class HandlerChainFactory
{
    public function build(
        ServerConfig $config,
        SocketHttpServer $server,
        PsrLogger $logger,
        HttpKernelInterface $kernel,
        ?ErrorRendererInterface $errorRenderer,
        RequestResetter $resetter,
        ContainerInterface $container,
        ?\Closure $onShutdownRequested,
    ): RequestHandler {
        // 1. Base Symfony handler
        $handlerArgs = [];
        if ($config->maxRequests > 0) {
            $handlerArgs['maxRequests'] = $config->maxRequests;
        }
        $handlerArgs['shutdownTimeout'] = $config->shutdownTimeout;
        $handlerArgs['onShutdownRequested'] = $onShutdownRequested;
        $handlerArgs['resetter'] = $resetter;
        $handlerArgs['maxBodySize'] = $config->maxBodySize;

        $symfonyHandler = new SymfonyRequestHandler($kernel, $logger, $errorRenderer, ...$handlerArgs);

        // 2. Request timeout middleware
        if ($config->requestTimeout > 0) {
            $symfonyHandler = stackMiddleware(
                $symfonyHandler,
                new RequestTimeoutMiddleware($config->requestTimeout),
            );
        }

        // 3. Liveness probe
        $livenessHandler = new LivenessHandler();

        // 4. Static file serving
        $appHandler = $this->buildStaticFileHandler($config, $server, $symfonyHandler, $logger);

        // 5. Liveness routing wrapper
        $wrappedHandler = $this->buildLivenessWrapper($livenessHandler, $appHandler);

        // 6. WebSocket router (optional)
        return $this->buildWebSocketHandler($config, $server, $logger, $container, $wrappedHandler);
    }

    private function buildStaticFileHandler(
        ServerConfig $config,
        SocketHttpServer $server,
        RequestHandler $symfonyHandler,
        PsrLogger $logger,
    ): RequestHandler {
        if (!$config->staticFilesEnabled) {
            return $symfonyHandler;
        }

        $documentRoot = new DocumentRoot($server, new DefaultErrorHandler(), $config->staticFilesPublicDir);

        if ($config->staticFilesIndexes !== []) {
            $documentRoot->setIndexes($config->staticFilesIndexes);
        }

        $documentRoot->setExpiresPeriod($config->staticFilesExpiresPeriod);
        $documentRoot->setFallback($symfonyHandler);

        return new class($documentRoot, $symfonyHandler) implements RequestHandler {
            public function __construct(
                private readonly DocumentRoot $documentRoot,
                private readonly RequestHandler $symfonyHandler,
            ) {}

            public function handleRequest(Request $request): Response
            {
                $path = $request->getUri()->getPath();

                if (\str_ends_with($path, '.php')) {
                    return $this->symfonyHandler->handleRequest($request);
                }

                return $this->documentRoot->handleRequest($request);
            }
        };
    }

    private function buildLivenessWrapper(
        LivenessHandler $livenessHandler,
        RequestHandler $appHandler,
    ): RequestHandler {
        return new class($livenessHandler, $appHandler) implements RequestHandler {
            public function __construct(
                private readonly LivenessHandler $liveness,
                private readonly RequestHandler $next,
            ) {}

            public function handleRequest(Request $request): Response
            {
                if ($request->getUri()->getPath() === '/healthz') {
                    return $this->liveness->handleRequest($request);
                }

                return $this->next->handleRequest($request);
            }
        };
    }

    private function buildWebSocketHandler(
        ServerConfig $config,
        SocketHttpServer $server,
        PsrLogger $logger,
        ContainerInterface $container,
        RequestHandler $fallbackHandler,
    ): RequestHandler {
        if (!$config->websocketEnabled || $config->websocketEndpoints === []) {
            return $fallbackHandler;
        }

        $router = new Router($server, $logger, new DefaultErrorHandler());

        foreach ($config->websocketEndpoints as $endpoint) {
            $clientHandler = $container->get($endpoint['handler']);

            $acceptor = $endpoint['allowed_origins'] !== []
                ? new AllowOriginAcceptor($endpoint['allowed_origins'])
                : new Rfc6455Acceptor();

            $websocket = new Websocket(
                httpServer: $server,
                logger: $logger,
                acceptor: $acceptor,
                clientHandler: $clientHandler,
            );

            $router->addRoute('GET', $endpoint['path'], $websocket);

            $logger->info('Registered WebSocket endpoint', [
                'path' => $endpoint['path'],
                'handler' => $endpoint['handler'],
            ]);
        }

        $router->setFallback($fallbackHandler);

        return $router;
    }
}
