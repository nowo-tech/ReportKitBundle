<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\DemoSignInType;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Demo home: the bundle status, the demo sign-in (alice / bob, no password) and links to the report pages.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%nowo_report_kit.enabled%')] private readonly bool $enabled,
        #[Autowire('%nowo_report_kit.api_prefix%')] private readonly string $apiPrefix,
        #[Autowire('%nowo_report_kit.table_prefix%')] private readonly string $tablePrefix,
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
        ]);
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
