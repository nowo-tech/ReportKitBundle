<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Worker;

use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Infrastructure\Messenger\RunScheduledReportHandler;
use Nowo\ReportKitBundle\Tests\Fixtures\Standard\User;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\RecordingMailer;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\LocaleAwareInterface;

use function gc_collect_cycles;
use function memory_get_usage;
use function scandir;
use function sys_get_temp_dir;

/**
 * Contract §11 / REQ-RUNTIME-002: a Messenger worker loop of 200 scheduled runs in one kept-alive kernel (no services
 * resetter), real PDF / XLSX / CSV renders, the owner in German, and an exception every 10th run (the mailer throws:
 * not handed over, recorded, rethrown for a retry). After every run the private temp directory is empty and the
 * translator is back on the default locale; memory drift stays bounded.
 */
final class WorkerScheduledRunsLoopTest extends KernelTestCase
{
    private const RUNS = 200;

    public function testTwoHundredRunsWithFailuresLeaveNothingBehind(): void
    {
        $temp = sys_get_temp_dir() . '/nowo-report-kit-worker-runs-temp';
        (new Filesystem())->mkdir($temp);
        $host   = Host::standard();
        $url    = Host::databaseUrl();
        $kernel = $this->bootKernel($host->bundleConfig(['temp_dir' => $temp, 'subscriptions' => ['max_per_owner' => 10]]), $url, $host->configure($url));
        $host->resetSchema($kernel);
        $em = $host->entityManager($kernel);
        $em->persist(new User('alice', 'alice@example.test', 'de'));
        $em->flush();
        $em->clear();

        $tests = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $tests);
        $subscriptions = $tests->get(SubscriptionsInterface::class);
        self::assertInstanceOf(SubscriptionsInterface::class, $subscriptions);
        $dispatcher = $tests->get(DueReportsDispatcher::class);
        self::assertInstanceOf(DueReportsDispatcher::class, $dispatcher);
        $runner = $tests->get(ScheduledReportRunner::class);
        self::assertInstanceOf(ScheduledReportRunner::class, $runner);
        // The handler a Messenger worker would call (this kernel has no bus, so the container drops the service).
        $handler = new RunScheduledReportHandler($runner);
        $mailer  = $tests->get(RecordingMailer::class);
        self::assertInstanceOf(RecordingMailer::class, $mailer);
        $clock = $tests->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $translator = $tests->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);

        $subscriptions->create($host->owner('alice'), 'orders', new ReportParameters(7, new ContextRef('project', 'a1')), new Schedule(Frequency::Daily, 1, 1, 7, 'UTC'), [ReportFormat::Csv, ReportFormat::Xlsx]);
        $baseline = 0;
        $sent     = 0;
        $failed   = 0;
        for ($i = 1; $i <= self::RUNS; ++$i) {
            $clock->modify('+1 day');
            $runs = $dispatcher->claim(new DispatchDueReportsMessage())->runs;
            self::assertCount(1, $runs, 'run ' . $i);
            $mailer->failures = $i % 10 === 0 ? 1 : 0;
            try {
                self::assertSame(RunStatus::Sent, $handler($runs[0])->status);
                ++$sent;
            } catch (RuntimeException $e) {
                self::assertSame('SMTP down', $e->getMessage());
                ++$failed;
            }
            self::assertSame(['.', '..'], scandir($temp), 'temp directory empty after run ' . $i);
            self::assertSame('en', $translator->getLocale(), 'locale restored after run ' . $i);
            if ($i === 20) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertSame(180, $sent);
        self::assertSame(20, $failed);
        self::assertCount(180, $mailer->sent);
        self::assertSame('de', $mailer->sent[0]->locale);
        self::assertStringEndsWith('.pdf', $mailer->sent[0]->attachments[0]->filename, 'PDF first');
        self::assertCount(3, $mailer->sent[0]->attachments);
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage() - $baseline, 'memory drift');
        (new Filesystem())->remove($temp);
    }
}
