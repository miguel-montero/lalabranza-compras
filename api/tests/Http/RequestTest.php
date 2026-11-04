<?php
declare(strict_types=1);

namespace KT\Tests\Http;

use KT\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testStripsApiPrefixOnSubdomainAndSubpath(): void
    {
        $this->assertSame('/receipts', (new Request('GET', '/api/receipts?x=1'))->path);
        $this->assertSame('/receipts', (new Request('GET', '/compras/api/receipts'))->path);
        $this->assertSame('/', (new Request('GET', '/compras/api'))->path);
        $this->assertSame('/auth/me', (new Request('GET', '/api/auth/me/'))->path);
    }
}
