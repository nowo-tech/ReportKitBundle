<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Form;

use DateTimeZone;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;
use function range;
use function sprintf;

/**
 * The *Schedule by e-mail* form (contract §8): frequency, weekday, day of the month (1–28), hour (when
 * `subscriptions.allow_hour_choice`), time zone and the extra formats (PDF is always attached when enabled, Q-O7).
 * POST with CSRF (token id `nowo_report_kit_schedule`). Every value is a choice; the controller turns the data into a
 * {@see Schedule}, which refuses anything outside the rules again.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ScheduleType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id. */
    public const CSRF_TOKEN_ID = 'nowo_report_kit_schedule';

    /**
     * Adds the schedule fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void { // @igor-ignore - FormKit runs the closure at once and keeps nothing
            $frequencies = [];
            /** @var list<string> $allowed */
            $allowed = $options['frequencies'];
            foreach ($allowed as $frequency) {
                $frequencies['schedule.frequency.' . $frequency] = $frequency;
            }
            $weekdays = [];
            foreach (range(1, 7) as $day) {
                $weekdays['schedule.weekday.' . $day] = $day;
            }
            $hours = [];
            foreach (range(0, 23) as $hour) {
                $hours[sprintf('%02d:00', $hour)] = $hour;
            }
            $zones = DateTimeZone::listIdentifiers();
            $this->addTypedField('frequency', ChoiceType::class, ['label' => 'schedule.frequency.label', 'help' => false, 'choices' => $frequencies, 'placeholder' => false, 'attr' => ['autofocus' => true]]);
            $this->addTypedField('weekday', ChoiceType::class, ['label' => 'schedule.weekday.label', 'help' => false, 'choices' => $weekdays, 'placeholder' => false]);
            $this->addTypedField('month_day', ChoiceType::class, ['label' => 'schedule.month_day', 'help' => false, 'choices' => array_combine(range(1, 28), range(1, 28)), 'choice_translation_domain' => false, 'placeholder' => false]);
            if ($options['allow_hour']) {
                $this->addTypedField('hour', ChoiceType::class, ['label' => 'schedule.hour', 'help' => false, 'choices' => $hours, 'choice_translation_domain' => false, 'placeholder' => false]);
            }
            $this->addTypedField('timezone', ChoiceType::class, ['label' => 'schedule.timezone', 'help' => false, 'choices' => array_combine($zones, $zones), 'choice_translation_domain' => false, 'placeholder' => false]);
            /** @var list<string> $formats */
            $formats = $options['formats'];
            if ($formats !== []) {
                $choices = [];
                foreach ($formats as $format) {
                    $choices['schedule.format.' . $format] = $format;
                }
                $this->addTypedField('formats', ChoiceType::class, ['label' => 'schedule.formats', 'help' => false, 'choices' => $choices, 'multiple' => true, 'expanded' => true, 'required' => false]);
            }
        });
    }

    /**
     * POST, CSRF on, array data; `frequencies` (allowed values), `formats` (extra format values), `allow_hour`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'method'             => 'POST',
            'frequencies'        => ['daily', 'weekly', 'monthly'],
            'formats'            => [],
            'allow_hour'         => true,
            'translation_domain' => NowoReportKitBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('frequencies', 'array');
        $resolver->setAllowedTypes('formats', 'array');
        $resolver->setAllowedTypes('allow_hour', 'bool');
    }

    /**
     * Fields are `schedule[frequency]`, … .
     */
    public function getBlockPrefix(): string
    {
        return 'schedule';
    }
}
