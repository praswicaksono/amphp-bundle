<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class AmphpExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/Resources/config'));
        $loader->load('services.php');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('amphp.host', $config['host']);
        $container->setParameter('amphp.port', $config['port']);

        $container->setParameter('amphp.dbal.max_connections', $config['dbal']['max_connections']);
        $container->setParameter('amphp.dbal.idle_timeout', $config['dbal']['idle_timeout']);
        $container->setParameter('amphp.dbal.ping_interval', $config['dbal']['ping_interval']);

        $container->setParameter('amphp.gc_interval', $config['gc_interval']);
        $container->setParameter('amphp.max_requests', $config['max_requests']);
        $container->setParameter('amphp.workers', $config['workers']);
        $container->setParameter('amphp.shutdown_timeout', $config['shutdown_timeout']);

        // ── TLS configuration ──
        $container->setParameter('amphp.tls.enabled', $config['tls']['enabled']);
        $container->setParameter('amphp.tls.cert_file', $config['tls']['cert_file']);
        $container->setParameter('amphp.tls.key_file', $config['tls']['key_file']);
        $container->setParameter('amphp.tls.passphrase', $config['tls']['passphrase']);
        $container->setParameter('amphp.tls.sni_certs', $config['tls']['sni_certs']);
        $container->setParameter('amphp.tls.min_version', $config['tls']['min_version']);
        $container->setParameter('amphp.tls.ciphers', $config['tls']['ciphers']);
        $container->setParameter('amphp.tls.security_level', $config['tls']['security_level']);
        $container->setParameter('amphp.tls.alpn_protocols', $config['tls']['alpn_protocols']);
        $container->setParameter('amphp.tls.verify_peer', $config['tls']['verify_peer']);
        $container->setParameter('amphp.tls.verify_peer_name', $config['tls']['verify_peer_name']);
        $container->setParameter('amphp.tls.verify_depth', $config['tls']['verify_depth']);
        $container->setParameter('amphp.tls.ca_file', $config['tls']['ca_file']);
        $container->setParameter('amphp.tls.ca_path', $config['tls']['ca_path']);
        $container->setParameter('amphp.tls.capture_peer', $config['tls']['capture_peer']);

        $container->setParameter('amphp.static_files.enabled', $config['static_files']['enabled']);

        $staticFilesPublicDir = $config['static_files']['public_dir'] ?? null;
        if ($staticFilesPublicDir === null || $staticFilesPublicDir === '') {
            $staticFilesPublicDir = $container->getParameter('kernel.project_dir') . '/public';
        }
        $container->setParameter('amphp.static_files.public_dir', $staticFilesPublicDir);

        $container->setParameter('amphp.static_files.indexes', $config['static_files']['indexes']);
        $container->setParameter('amphp.static_files.expires_period', $config['static_files']['expires_period']);

        $container->setParameter('amphp.request_timeout', $config['request_timeout']);
        $container->setParameter('amphp.header_timeout', $config['header_timeout']);
        $container->setParameter('amphp.body_timeout', $config['body_timeout']);
        $container->setParameter('amphp.max_body_size', $config['max_body_size']);

        $container->setParameter('amphp.readiness.enabled', $config['readiness']['enabled']);
        $container->setParameter('amphp.readiness.check_db', $config['readiness']['check_db']);

        $container->setParameter('amphp.websocket.enabled', $config['websocket']['enabled'] ?? true);
    }
}
