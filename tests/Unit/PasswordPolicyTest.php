<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsLongPassphrase(): void
    {
        self::expectNotToPerformAssertions();
        (new PasswordPolicy())->validate('správná dlouhá přístupová fráze');
    }

    #[DataProvider('invalidPasswords')]
    public function testRejectsUnsafeLength(string $password): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PasswordPolicy())->validate($password);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'too short' => ['kratke-heslo'];
        yield 'unreasonably long' => [str_repeat('x', 4097)];
    }
}
