<?php
namespace OmekaTest\DataType;

use Omeka\DataType\Uri;
use Omeka\Test\TestCase;

class UriTest extends TestCase
{
    public function testRejectsC0()
    {
        $uri = new Uri;
        $this->assertFalse($uri->uriIsValid("\x01http://example.com"));
        $this->assertFalse($uri->uriIsValid("http\x01://example.com"));
    }

    public function testRejectsJavascript()
    {
        $uri = new Uri;
        $this->assertFalse($uri->uriIsValid("javascript:alert()"));
        $this->assertFalse($uri->uriIsValid("jav\tascript:alert()"));
        $this->assertFalse($uri->uriIsValid("jav\r\nascript:alert()"));
        $this->assertFalse($uri->uriIsValid("  javascript:alert()  "));
    }
}
