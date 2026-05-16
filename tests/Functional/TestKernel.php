<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Tests\Functional;

use PRSW\AmphpBundle\AmphpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new AmphpBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return \sys_get_temp_dir() . '/amphp_bundle_test_' . \bin2hex(\random_bytes(4));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void {}
}
