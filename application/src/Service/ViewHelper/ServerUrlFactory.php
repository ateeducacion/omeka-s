<?php

namespace Omeka\Service\ViewHelper;

use Laminas\View\Helper\ServerUrl;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

/**
 * Service factory for the serverUrl view helper.
 */
class ServerUrlFactory implements FactoryInterface
{
    /**
     * Create and return the serverUrl view helper
     *
     * @return ServerUrl
     */
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $helper = new ServerUrl;
        $serverUrlSetting = $services->get('Omeka\Settings')->get('server_url', '');
        if (!(is_string($serverUrlSetting) && $serverUrlSetting)) {
            return $helper;
        }

        $parts = parse_url($serverUrlSetting);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if ($scheme !== 'http' && $scheme !== 'https') {
            return $helper;
        }
        if (!strlen($host)) {
            return $helper;
        }

        if (isset($parts['port'])) {
            $helper->setPort($parts['port']);
        } elseif ($scheme === 'https') {
            $helper->setPort(443);
        }

        if ($scheme === 'https') {
            $helper->setScheme('https');
        }
        $helper->setHost($host);
        return $helper;
    }
}
