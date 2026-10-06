<?php
namespace OmekaTest\DataType;

use Omeka\Site\Navigation\Link\Url;
use Omeka\Stdlib\ErrorStore;
use Omeka\Test\TestCase;

class UrlTest extends TestCase
{
    public function testAcceptsValid()
    {
        $urlLink = new Url;
        $errorStore = new ErrorStore;
        $this->assertTrue($urlLink->isValid(['label' => 'Label', 'url' => 'http://example.com'], $errorStore));
    }

    public function testRejectsMissingKeys()
    {
        $urlLink = new Url;
        $errorStore = new ErrorStore;
        $this->assertFalse($urlLink->isValid([], $errorStore));
        $this->assertFalse($urlLink->isValid(['label' => 'Label'], $errorStore));
        $this->assertFalse($urlLink->isValid(['url' => 'http://example.com'], $errorStore));
    }

    public static function emptyLabelProvider()
    {
        return [
            [''],
            [' '],
            ['    '],
            ["\t\r\n"],
        ];
    }

    /**
     * @dataProvider emptyLabelProvider
     */
    public function testRejectsEmptyLabel($label)
    {
        $urlLink = new Url;
        $errorStore = new ErrorStore;
        $this->assertFalse($urlLink->isValid(['label' => $label, 'url' => 'http://example.com'], $errorStore));
    }

    public static function InvalidUrlProvider()
    {
        return [
            [''],
            [' '],
            ['   '],
            ["\t\r\n"],
            ["\x01http://example.com"],
            ["http\x01://example.com"],
            ['javascript:alert()'],
            ["jav\tascript:alert()"],
            ["jav\r\nascript:alert()"],
            ['  javascript:alert()  '],
            ["\fjavascript:alert()"],
        ];
    }

    /**
     * @dataProvider invalidUrlProvider()
     */
    public function testRejectsInvalidUrl($url)
    {
        $urlLink = new Url;
        $errorStore = new ErrorStore;
        $this->assertFalse($urlLink->isValid(['label' => 'Label', 'url' => $url], $errorStore));
    }
}
