<?php
namespace Omeka\Site\Navigation\Link;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Stdlib\ErrorStore;

class Url implements LinkInterface
{
    public function getName()
    {
        return 'Custom URL'; // @translate
    }

    public function getFormTemplate()
    {
        return 'common/navigation-link-form/url';
    }

    public function isValid(array $data, ErrorStore $errorStore)
    {
        $label = trim($data['label'] ?? '');
        $url = str_replace(["\t", "\r", "\n"], '', trim($data['url'] ?? ''));
        if ('' === $label) {
            $errorStore->addError('o:navigation', 'Invalid navigation: URL link missing label');
            return false;
        }
        if ('' === $url) {
            $errorStore->addError('o:navigation', 'Invalid navigation: URL link missing URL');
            return false;
        }
        // Reject URLs with any of the C0 control characters after trimming and tab/newline stripping
        // They're unlikely in real data, browsers trim them and they trip up parse_url, so just reject them
        if (preg_match('/[\x00-\x1F]/', $url)) {
            $errorStore->addError('o:navigation', 'Invalid navigation: URL link contains invalid character');
            return false;
        }
        if ('javascript' === parse_url(strtolower($url), \PHP_URL_SCHEME)) {
            $errorStore->addError('o:navigation', 'Invalid navigation: URL link invalid scheme');
            return false;
        }
        return true;
    }

    public function getLabel(array $data, SiteRepresentation $site)
    {
        return isset($data['label']) && '' !== trim($data['label'])
            ? $data['label'] : null;
    }

    public function toZend(array $data, SiteRepresentation $site)
    {
        return [
            'type' => 'uri',
            'uri' => $data['url'],
            'target' => (isset($data['target_blank']) && $data['target_blank']) ? '_blank' : null,

        ];
    }

    public function toJstree(array $data, SiteRepresentation $site)
    {
        return [
            'label' => $data['label'],
            'url' => $data['url'],
            'target_blank' => $data['target_blank'] ?? false,
        ];
    }
}
