<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use LogicException;
use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Contract\MailAttachment;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ScheduledReportMail;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Infrastructure\Mail\DisabledReportMailer;
use Nowo\ReportKitBundle\Infrastructure\Mail\SymfonyReportMailer;
use Nowo\ReportKitBundle\Infrastructure\Owner\DoctrineOwnerDirectory;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function dirname;

/**
 * The default mailer (recipient, subject in the owner's locale, bodies, attachments, readiness bridge, link-only
 * mail), the disabled mailer, and the Doctrine owner directory conventions.
 */
final class MailAndDirectoryTest extends TestCase
{
    public function testTheMailGoesToTheOwnerOnlyWithTheFilesAndAbsoluteLinks(): void
    {
        [$mailer, $sent] = $this->mailer('reports@example.test');
        $mailer->send($this->mail([new MailAttachment('report-orders.pdf', 'application/pdf', '%PDF-1.7')]));

        $email = $sent->messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('"Alice" <alice@example.test>', $email->getTo()[0]->toString());
        self::assertCount(1, $email->getTo());
        self::assertSame([], $email->getCc());
        self::assertSame([], $email->getBcc());
        self::assertSame('reports@example.test', $email->getFrom()[0]->getAddress());
        self::assertSame('Tu informe: Pedidos <script>', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Hola, Alice:', $html);
        self::assertStringContainsString('Pedidos &lt;script&gt;', $html, 'escaped');
        self::assertStringContainsString('href="https://reports.example.test/reports/orders?period=7"', $html);
        self::assertStringContainsString('El informe va adjunto.', $html);
        self::assertStringContainsString('lang="es"', $html);
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Pedidos <script>', $text);
        self::assertStringContainsString('https://reports.example.test/reports', $text);
        self::assertSame('report-orders.pdf', $email->getAttachments()[0]->getFilename());
        self::assertSame('01933b1c-5d2e-7a3b-8c4d-00000000a001', $email->getHeaders()->get('X-Report-Kit-Subscription')?->getBodyAsString());
        self::assertTrue($mailer->isReady());
    }

    public function testDroppedFilesAndNoPagesStillMakeAMail(): void
    {
        [$mailer, $sent] = $this->mailer();
        $mailer->send($this->mail([], true, null));
        $email = $sent->messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([], $email->getFrom());
        self::assertStringContainsString('demasiado grandes', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('href=', (string) $email->getHtmlBody());
        self::assertSame('Hola:', trim(explode("\n", (string) $email->getTextBody())[0]));

        $mailer->send($this->mail([], false, 'https://x.test/r'));
        $second = $sent->messages[1];
        self::assertInstanceOf(Email::class, $second);
        self::assertStringContainsString('Abre el informe para verlo.', (string) $second->getTextBody());
    }

    public function testReadinessFollowsMailKit(): void
    {
        $ready = new class {
            public bool $ready = false;

            public function isOutboundMailReady(): bool
            {
                return $this->ready;
            }
        };
        [$mailer] = $this->mailer(null, $ready);
        self::assertFalse($mailer->isReady());
        $ready->ready = true;
        self::assertTrue($mailer->isReady());
        [$odd] = $this->mailer(null, new stdClass());
        self::assertTrue($odd->isReady(), 'an object without the method is ignored');
    }

    public function testTheDisabledMailerIsNeverReady(): void
    {
        $mailer = new DisabledReportMailer();
        self::assertFalse($mailer->isReady());
        $this->expectException(LogicException::class);
        $mailer->send($this->mail([]));
    }

    public function testTheDoctrineDirectoryReadsTheConventionalGetters(): void
    {
        $user = new class {
            public function getEmail(): string
            {
                return 'alice@example.test';
            }

            public function getLocale(): string
            {
                return 'es';
            }

            public function getTimezone(): string
            {
                return 'Europe/Madrid';
            }

            public function isEnabled(): bool
            {
                return false;
            }

            public function getDisplayName(): string
            {
                return '';
            }

            public function getName(): string
            {
                return 'Alice';
            }
        };
        $directory = $this->directory($user);
        $alice     = new OwnerRef('user', 'alice');
        self::assertSame($user, $directory->userOf($alice));
        self::assertSame(['alice@example.test', 'es', 'Europe/Madrid', 'Alice', false], [$directory->email($alice), $directory->locale($alice), $directory->timezone($alice), $directory->displayName($alice), $directory->isActive($alice)]);
        self::assertNull($directory->userOf(new OwnerRef('member', 'alice')), 'another owner type has no user');

        $plain = $this->directory(new stdClass());
        self::assertTrue($plain->isActive($alice), 'no flag: active');
        self::assertNull($plain->email($alice));

        $gone = $this->directory(null);
        self::assertFalse($gone->isActive($alice));
        self::assertNull($gone->locale($alice));

        $active = $this->directory(new class {
            public function isActive(): string
            {
                return 'yes';
            }
        });
        self::assertTrue($active->isActive($alice), 'a non-boolean flag does not deactivate');

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        self::assertNull((new DoctrineOwnerDirectory($registry, stdClass::class))->userOf($alice));
    }

    private function directory(?object $user): DoctrineOwnerDirectory
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->with(['uuid' => 'alice'])->willReturn($user);
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return new DoctrineOwnerDirectory($registry, stdClass::class, 'uuid', 'user');
    }

    /**
     * @return array{SymfonyReportMailer, MemoryMailer}
     */
    private function mailer(?string $from = null, ?object $readiness = null): array
    {
        $sent       = new MemoryMailer();
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3) . '/src/Resources/translations/NowoReportKitBundle.es.yaml', 'es', 'NowoReportKitBundle');
        $translator->setLocale('es');
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 3) . '/src/Resources/views', 'NowoReportKit');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'name']);
        $twig->addExtension(new TranslationExtension($translator));

        return [new SymfonyReportMailer($sent, $twig, $translator, new DefaultReportBrandResolver(null, 'project'), '@NowoReportKit/email/scheduled_report.html.twig', $from, $readiness), $sent];
    }

    /**
     * @param list<MailAttachment> $attachments
     */
    private function mail(array $attachments, bool $dropped = false, ?string $url = 'https://reports.example.test/reports/orders?period=7'): ScheduledReportMail
    {
        $report = new Report('Pedidos <script>', 'Últimos 7 días', new DateTimeImmutable('2026-11-24T06:00:00Z'), [], 'orders', 'Resumen', glance: 'Todo bien');

        return new ScheduledReportMail(new SubscriptionId('01933b1c-5d2e-7a3b-8c4d-00000000a001'), new OwnerRef('user', 'alice'), 'alice@example.test', 'es', $url === null ? null : 'Alice', $report, $attachments, $url, $url === null ? null : 'https://reports.example.test/reports', $dropped, new DateTimeImmutable('2026-11-24T06:00:00Z'));
    }
}

/**
 * Mailer double: keeps every message.
 */
final class MemoryMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }
}
