<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Opportunity\TenderStatusFormFactory;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\CsrfProtection;
use PHPUnit\Framework\TestCase;

final class TenderStatusFormFactoryTest extends TestCase
{
    public function testActiveAndClosedFormsRenderAndForwardSubmittedState(): void
    {
        $factory = new TenderStatusFormFactory();
        $submitted = [];
        $active = $factory->create('active', 4, static function (Form $form, array $values) use (&$submitted): void {
            $submitted[] = $values;
        });
        self::assertInstanceOf(CsrfProtection::class, $active[Form::ProtectorId]);
        $active->removeComponent($active[Form::ProtectorId]);
        $activeHtml = (string) $active;
        self::assertStringContainsString('Uzavřít výběrové řízení', $activeHtml);
        self::assertStringContainsString('value="closed"', $activeHtml);
        self::assertStringContainsString('value="4"', $activeHtml);
        $active->removeComponent($active['save']);
        ($active->onSuccess[0])($active);
        self::assertSame('closed', $submitted[0]['event']);
        self::assertSame('4', (string) $submitted[0]['lockVersion']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', (string) $submitted[0]['idempotencyKey']);

        $closed = $factory->create('closed', 5, static function (Form $form, array $values) use (&$submitted): void {
            $submitted[] = $values;
        });
        self::assertInstanceOf(CsrfProtection::class, $closed[Form::ProtectorId]);
        $closed->removeComponent($closed[Form::ProtectorId]);
        $closedHtml = (string) $closed;
        self::assertStringContainsString('Znovu otevřít', $closedHtml);
        self::assertStringContainsString('value="reopened"', $closedHtml);
        $closed->removeComponent($closed['save']);
        ($closed->onSuccess[0])($closed);
        self::assertSame('reopened', $submitted[1]['event']);
        self::assertSame('5', (string) $submitted[1]['lockVersion']);
    }
}
