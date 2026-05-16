<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use PRSW\AmphpBundle\Command\AmphpStartCommand;
use PRSW\AmphpBundle\Metrics\MetricFormatterInterface;
use PRSW\AmphpBundle\Metrics\MetricsController;
use PRSW\AmphpBundle\Metrics\PrometheusFormatter;
use PRSW\AmphpBundle\ReadinessCheck\Check\DbalReadinessCheck;
use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckController;
use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckService;
use PRSW\AmphpBundle\Runtime\Bootstrap\GcCollectorHook;
use PRSW\AmphpBundle\Runtime\Bridge\AmpToSymfonyRequestConverter;
use PRSW\AmphpBundle\Runtime\Bridge\DatabaseResetter;
use PRSW\AmphpBundle\Runtime\Bridge\DebugLoggerResetter;
use PRSW\AmphpBundle\Runtime\Bridge\RequestResetter;
use PRSW\AmphpBundle\Runtime\Bridge\SymfonyToAmpResponseConverter;
use PRSW\AmphpBundle\Runtime\Server\ServerRunnerFactory;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // No autowire/autoconfigure defaults — bundle services define
    // their needs explicitly (Symfony best practices for reusable bundles).

    // --- Console command ---
    $services->set('amphp.start_command', AmphpStartCommand::class)
        ->autowire()
        ->tag('console.command');

    // --- GC collector hook (runs after kernel boot) ---
    $services->set('amphp.gc_collector_hook', GcCollectorHook::class)
        ->arg('$intervalSeconds', '%amphp.gc_interval%')
        ->tag('amphp.after_boot_hook');

    // --- Request/response converters ---
    $services->set('amphp.request_converter', AmpToSymfonyRequestConverter::class);
    $services->set('amphp.response_converter', SymfonyToAmpResponseConverter::class);

    // --- Request resetters (run after each request in the finally block) ---
    $services->set('amphp.database_resetter', DatabaseResetter::class)
        ->tag('amphp.resetter');

    $services->set('amphp.debug_logger_resetter', DebugLoggerResetter::class)
        ->autowire()
        ->tag('amphp.resetter');

    $services->set('amphp.request_resetter', RequestResetter::class)
        ->arg('$resetters', tagged_iterator('amphp.resetter'))
        ->public();

    // --- Readiness check services ---
    $services->set('amphp.readiness_check_service', ReadinessCheckService::class)
        ->arg('$checks', []); // populated by ReadinessCheckIntegrationPass

    $services->set('amphp.readiness_check_controller', ReadinessCheckController::class)
        ->arg('$enabled', '%amphp.readiness.enabled%')
        ->autowire()
        ->tag('controller.service_arguments')
        ->tag('routing.controller');

    $services->set('amphp.dbal_readiness_check', DbalReadinessCheck::class)
        ->arg('$connection', null) // overridden by ReadinessCheckIntegrationPass
        ->tag('amphp.readiness_check');

    // --- Metrics ---
    $services->set('amphp.prometheus_formatter', PrometheusFormatter::class);
    $services->alias(MetricFormatterInterface::class, 'amphp.prometheus_formatter');

    $services->set('amphp.metrics_controller', MetricsController::class)
        ->arg('$collectors', []) // populated by MetricsControllerIntegrationPass
        ->autowire()
        ->tag('controller.service_arguments')
        ->tag('routing.controller');

    // --- Server runner factory (user-overridable alias) ---
    $services->set('amphp.server_runner_factory', ServerRunnerFactory::class);
    $services->alias(ServerRunnerFactory::class, 'amphp.server_runner_factory');

    // --- Aliases for autowiring (FQCN → prefixed service ID) ---
    $services->alias(DatabaseResetter::class, 'amphp.database_resetter');
    $services->alias(DebugLoggerResetter::class, 'amphp.debug_logger_resetter');
    $services->alias(RequestResetter::class, 'amphp.request_resetter');
    $services->alias(ReadinessCheckService::class, 'amphp.readiness_check_service');
    $services->alias(ReadinessCheckController::class, 'amphp.readiness_check_controller');
    $services->alias(DbalReadinessCheck::class, 'amphp.dbal_readiness_check');
    $services->alias(PrometheusFormatter::class, 'amphp.prometheus_formatter');
    $services->alias(MetricsController::class, 'amphp.metrics_controller');
    $services->alias(GcCollectorHook::class, 'amphp.gc_collector_hook');
    $services->alias(AmphpStartCommand::class, 'amphp.start_command');
};
