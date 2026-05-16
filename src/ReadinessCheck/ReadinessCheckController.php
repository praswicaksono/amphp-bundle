<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\ReadinessCheck;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReadinessCheckController
{
    public function __construct(
        private ReadinessCheckService $readinessCheckService,
        private bool $enabled,
    ) {}

    #[Route('/readyz', name: 'amphp_readyz', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->enabled) {
            return new JsonResponse(
                ['status' => 'disabled'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $results = $this->readinessCheckService->runAll();
        $aggregate = $this->readinessCheckService->aggregate($results);

        $statusCode = match ($aggregate->status) {
            ReadinessCheckStatus::Ok, ReadinessCheckStatus::Degraded => Response::HTTP_OK,
            ReadinessCheckStatus::Unavailable => Response::HTTP_SERVICE_UNAVAILABLE,
        };

        return new JsonResponse(
            [
                'status' => $aggregate->status->value,
                'message' => $aggregate->message,
                'checks' => $aggregate->info,
            ],
            $statusCode,
        );
    }
}
