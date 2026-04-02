<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\JobEndpointFencingTokenParser;
use PHPUnit\Framework\TestCase;

final class JobEndpointFencingTokenParserTest extends TestCase
{
    public function testParsesValidPositiveIntegerTokens(): void
    {
        $parser = new JobEndpointFencingTokenParser();

        self::assertSame(1, $parser->parse(1));
        self::assertSame(42, $parser->parse('42'));
    }

    public function testRejectsInvalidTokens(): void
    {
        $parser = new JobEndpointFencingTokenParser();

        self::assertNull($parser->parse(0));
        self::assertNull($parser->parse('0'));
        self::assertNull($parser->parse('abc'));
    }
}
