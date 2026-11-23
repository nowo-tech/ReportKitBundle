<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Mail;

use Nowo\ReportKitBundle\Application\Chart\ReportChartSvg;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportMailerInterface;
use Nowo\ReportKitBundle\Contract\ScheduledReportMail;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function is_bool;
use function method_exists;

/**
 * Default {@see ReportMailerInterface} (contract §6.3): renders `mail.template` (HTML) and the bundle's text twin in
 * the owner's locale (the runner switches it), attaches the files and hands the mail to Symfony Mailer. The only
 * recipient is the address of the mail (the directory's, at run time); the sender is `mail.from`, else the mailer's
 * own `framework.mailer.headers` / envelope. The brand (name, accent colour) comes from the report's brand context.
 *
 * Readiness: MailKit's `OutboundMailReadinessInterface` when installed (`bridges.mail_kit`), else always ready.
 * Stateless (worker mode).
 */
final readonly class SymfonyReportMailer implements ReportMailerInterface
{
    /** Text twin of the default template. */
    public const TEXT_TEMPLATE = '@NowoReportKit/email/scheduled_report.txt.twig';

    /**
     * @param string $template `mail.template`
     * @param string|null $from `mail.from`
     * @param object|null $readiness MailKit's `OutboundMailReadinessInterface` (duck-typed: the bridge is optional)
     */
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private ReportBrandResolverInterface $brands,
        private string $template = '@NowoReportKit/email/scheduled_report.html.twig',
        private ?string $from = null,
        private ?object $readiness = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isReady(): bool
    {
        if ($this->readiness === null || !method_exists($this->readiness, 'isOutboundMailReady')) {
            return true;
        }
        $ready = $this->readiness->isOutboundMailReady();

        return !is_bool($ready) || $ready;
    }

    /**
     * {@inheritDoc}
     */
    public function send(ScheduledReportMail $mail): void
    {
        $brand   = $this->brands->brandFor($mail->report->brandContext);
        $context = [
            'mail'   => $mail,
            'report' => $mail->report,
            'brand'  => ['name' => $brand->name, 'accent' => ReportChartSvg::colour($brand->light->primary)],
        ];
        $email = (new Email())
            ->to(new Address($mail->to, $mail->recipientName ?? ''))
            ->subject($this->translator->trans('mail.subject', ['%title%' => $mail->report->title], NowoReportKitBundle::TRANSLATION_DOMAIN, $mail->locale))
            ->html($this->twig->render($this->template, $context))
            ->text($this->twig->render(self::TEXT_TEMPLATE, $context));
        if ($this->from !== null) {
            $email->from($this->from);
        }
        foreach ($mail->attachments as $attachment) {
            $email->attach($attachment->content, $attachment->filename, $attachment->contentType);
        }
        $email->getHeaders()->addTextHeader('X-Report-Kit-Subscription', $mail->subscriptionId->value);
        $this->mailer->send($email);
    }
}
