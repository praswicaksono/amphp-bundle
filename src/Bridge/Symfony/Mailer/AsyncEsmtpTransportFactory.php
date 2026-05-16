<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Mailer;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class AsyncEsmtpTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!\in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'smtp', $this->getSupportedSchemes());
        }

        $autoTls =
            '' === $dsn->getOption('auto_tls') || filter_var($dsn->getOption('auto_tls', true), \FILTER_VALIDATE_BOOL);
        $tls = 'smtps' === $dsn->getScheme() ? true : ($autoTls ? null : false);
        $port = $dsn->getPort(0);
        $host = $dsn->getHost();

        $stream = new AmpSocketStream();
        $stream->setHost($host);
        $stream->setPort($port);

        // Configure TLS
        if (null === $tls) {
            $tls = \defined('OPENSSL_VERSION_NUMBER') && 0 === $port && 'localhost' !== $host;
        }
        if (!$tls) {
            $stream->disableTls();
        }

        $transport = new EsmtpTransport($host, $port, $tls, $this->dispatcher, $this->logger, $stream);
        $transport->setAutoTls($autoTls);
        $transport->setRequireTls($dsn->getBooleanOption('require_tls'));

        if ('' !== ($sourceIp = $dsn->getOption('source_ip', ''))) {
            $stream->setSourceIp($sourceIp);
        }
        $streamOptions = $stream->getStreamOptions();

        if (
            '' !== $dsn->getOption('verify_peer')
            && !filter_var($dsn->getOption('verify_peer', true), \FILTER_VALIDATE_BOOL)
        ) {
            $streamOptions['ssl']['verify_peer'] = false;
            $streamOptions['ssl']['verify_peer_name'] = false;
        }

        if (null !== ($peerFingerprint = $dsn->getOption('peer_fingerprint'))) {
            $streamOptions['ssl']['peer_fingerprint'] = $peerFingerprint;
        }

        $stream->setStreamOptions($streamOptions);

        if ($user = $dsn->getUser()) {
            $transport->setUsername($user);
        }

        if ($password = $dsn->getPassword()) {
            $transport->setPassword($password);
        }

        if (null !== ($localDomain = $dsn->getOption('local_domain'))) {
            $transport->setLocalDomain($localDomain);
        }

        if (null !== ($maxPerSecond = $dsn->getOption('max_per_second'))) {
            $transport->setMaxPerSecond((float) $maxPerSecond);
        }

        if (null !== ($restartThreshold = $dsn->getOption('restart_threshold'))) {
            $transport->setRestartThreshold(
                (int) $restartThreshold,
                (int) $dsn->getOption('restart_threshold_sleep', 0),
            );
        }

        if (null !== ($pingThreshold = $dsn->getOption('ping_threshold'))) {
            $transport->setPingThreshold((int) $pingThreshold);
        }

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return ['smtp', 'smtps'];
    }
}
