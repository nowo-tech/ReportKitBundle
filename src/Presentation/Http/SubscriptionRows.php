<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use DateTimeZone;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * The display row of a subscription on the list page (contract §8): type title, parameters line, schedule line, next
 * run in the subscription's time zone, status (enabled / paused with the reason, last outcome). Plain strings: the
 * table escapes them. Stateless.
 *
 * @internal
 */
final class SubscriptionRows
{
    private const DOMAIN = NowoReportKitBundle::TRANSLATION_DOMAIN;

    /**
     * @return array{id: string, enabled: bool, report: string, parameters: string, schedule: string, next_run: string, status: string}
     */
    public static function row(SubscriptionView $view, ?ReportTypeInterface $type, TranslatorInterface $translator): array
    {
        $parameters = $view->parameters;
        $line       = $translator->trans('subscriptions.parameters', ['%days%' => $parameters->periodDays], self::DOMAIN);
        if ($parameters->context instanceof ContextRef) {
            $line .= ' · ' . $parameters->context->id;
        }
        $schedule = $view->schedule;
        $hour     = sprintf('%02d:00', $schedule->hour);
        $when     = match ($schedule->frequency) {
            Frequency::Daily   => $translator->trans('subscriptions.schedule.daily', ['%hour%' => $hour], self::DOMAIN),
            Frequency::Weekly  => $translator->trans('subscriptions.schedule.weekly', ['%hour%' => $hour, '%weekday%' => $translator->trans('schedule.weekday.' . $schedule->weekday, [], self::DOMAIN)], self::DOMAIN),
            Frequency::Monthly => $translator->trans('subscriptions.schedule.monthly', ['%hour%' => $hour, '%day%' => $schedule->monthDay], self::DOMAIN),
        };
        $status = $view->enabled
            ? $translator->trans('subscriptions.status.enabled', [], self::DOMAIN)
            : $translator->trans('subscriptions.status.paused.' . ($view->pauseReason ?? PauseReason::Owner)->value, [], self::DOMAIN);
        if ($view->lastStatus instanceof RunStatus) {
            $status .= ' · ' . $translator->trans('subscriptions.last.' . $view->lastStatus->value, [], self::DOMAIN);
        }

        return [
            'id'         => $view->id->value,
            'enabled'    => $view->enabled,
            'report'     => $type instanceof ReportTypeInterface ? $translator->trans($type->labelKey(), [], $type->translationDomain() ?? 'messages') : $view->type,
            'parameters' => $line,
            'schedule'   => $when . ' (' . $schedule->timezone . ')',
            'next_run'   => $view->enabled ? $view->nextRunAt->setTimezone(new DateTimeZone($schedule->timezone))->format('Y-m-d H:i') : '—',
            'status'     => $status,
        ];
    }
}
