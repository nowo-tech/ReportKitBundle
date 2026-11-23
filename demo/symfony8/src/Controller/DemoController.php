<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\DemoSignInType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactoryInterface;
use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function sprintf;

/**
 * Demo home: the bundle status, the demo sign-in (alice / bob, no password), links to the report pages and (S3) a
 * "send my due reports now" button that makes the signed-in user's subscriptions due and runs them in this request.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%nowo_report_kit.enabled%')] private readonly bool $enabled,
        #[Autowire('%nowo_report_kit.api_prefix%')] private readonly string $apiPrefix,
        #[Autowire('%nowo_report_kit.table_prefix%')] private readonly string $tablePrefix,
        private readonly CsrfOnlyFormFactoryInterface $csrfForms,
        private readonly DueReportsDispatcher $dispatcher,
        private readonly ScheduledReportRunner $runner,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(): Response
    {
        try {
            $database = (string) $this->connection->fetchOne('SELECT VERSION()');
        } catch (Throwable) {
            $database = null;
        }

        return $this->render('demo/home.html.twig', [
            'enabled'      => $this->enabled,
            'api_prefix'   => $this->apiPrefix,
            'table_prefix' => $this->tablePrefix,
            'database'     => $database,
            'worker'       => ($_SERVER['FRANKENPHP_WORKER'] ?? null) !== null,
            'sign_in'      => $this->createForm(DemoSignInType::class, null, ['action' => $this->generateUrl('demo_sign_in')])->createView(),
            'run_due'      => $this->getUser() !== null ? $this->runDueForm()->createView() : null,
        ]);
    }

    /**
     * Demo only: makes the signed-in user's enabled subscriptions due now and runs them synchronously through the
     * bundle's claim and runner (what `nowo:report-kit:dispatch-due --sync` does for every owner). The mails land in
     * Mailpit.
     */
    #[Route('/demo/run-due', name: 'demo_run_due', methods: ['POST'])]
    public function runDue(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $form = $this->runDueForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->connection->executeStatement(
            sprintf('UPDATE %sreport_subscription SET next_run_at = ? WHERE owner_type = ? AND owner_id = ? AND enabled = ?', $this->tablePrefix),
            [$now->modify('-1 minute')->format('Y-m-d H:i:s'), 'user', $this->getUser()?->getUserIdentifier() ?? '', 1],
        );
        $sent = 0;
        foreach ($this->dispatcher->claim(new DispatchDueReportsMessage())->runs as $run) {
            try {
                $sent += $this->runner->run($run)->status?->value === 'sent' ? 1 : 0;
            } catch (Throwable) {
                // Recorded by the runner (failed, counted); the demo shows the status in the list.
            }
        }
        $this->addFlash('success', sprintf('%d scheduled report(s) sent: open Mailpit to read them.', $sent));

        return $this->redirectToRoute('nowo_report_kit_index', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @return \Symfony\Component\Form\FormInterface<mixed>
     */
    private function runDueForm(): \Symfony\Component\Form\FormInterface
    {
        return $this->csrfForms->createWithFields($this->generateUrl('demo_run_due'), 'demo_run_due', ['run' => '1']);
    }

    /**
     * Handled by DemoSignInAuthenticator; reached only without a valid submission.
     */
    #[Route('/sign-in', name: 'demo_sign_in', methods: ['POST'])]
    public function signIn(): RedirectResponse
    {
        return $this->redirectToRoute('demo_home', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/sign-out', name: 'demo_sign_out', methods: ['GET'])]
    public function signOut(): never
    {
        throw new LogicException('The firewall handles the sign-out.');
    }
}
