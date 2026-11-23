<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\ReportKitBundle\Contract\ParameterDefinition;
use Nowo\ReportKitBundle\Contract\ParameterType;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;
use function array_filter;

/**
 * The filter form of a report page (FormKit `filter` profile, GET, no CSRF, rootless so the query keys are `period`,
 * `context` and the extras by name). The controller passes the translated period and context choices; the extras
 * come from the type's {@see ParameterDefinition}s (labels in the type's domain). It only renders: the builder
 * resolves the query string against the schema on every request, so a forged value never reaches a type.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('filter')]
final class ReportFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds period, context (when the type takes one) and the extras.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void { // @igor-ignore - FormKit runs the closure at once and keeps nothing
            // help: false everywhere; the profile's convention keys (`.period.help`) do not exist for a rootless form.
            $this->addTypedField('period', ChoiceType::class, ['label' => 'filter.period', 'help' => false, 'choices' => $options['periods'], 'choice_translation_domain' => false, 'required' => true, 'placeholder' => false]);
            if ($options['contexts'] !== []) {
                $this->addTypedField('context', ChoiceType::class, [
                    'label'                     => 'filter.context',
                    'help'                      => false,
                    'choices'                   => $options['contexts'],
                    'choice_translation_domain' => false,
                    'required'                  => $options['context_required'],
                    'placeholder'               => $options['context_required'] ? false : 'filter.all_contexts',
                ]);
            }
            /** @var array<string, ParameterDefinition> $extra */
            $extra = $options['extra'];
            foreach ($extra as $name => $definition) {
                $common = ['label' => $definition->labelKey, 'help' => false, 'translation_domain' => $options['type_domain'], 'required' => false];
                match ($definition->type) {
                    ParameterType::Choice => $this->addTypedField($name, ChoiceType::class, $common + ['choices' => array_combine($definition->choices, $definition->choices), 'placeholder' => false]),
                    ParameterType::Int    => $this->addTypedField($name, IntegerType::class, $common + ['placeholder' => false, 'attr' => array_filter(['min' => $definition->min, 'max' => $definition->max], static fn (?int $bound): bool => $bound !== null)]),
                    ParameterType::Bool   => $this->addTypedField($name, ChoiceType::class, $common + ['choices' => ['filter.yes' => '1', 'filter.no' => '0'], 'choice_translation_domain' => NowoReportKitBundle::TRANSLATION_DOMAIN, 'placeholder' => false]),
                    ParameterType::Date   => $this->addTypedField($name, DateType::class, $common + ['placeholder' => false, 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'Y-m-d']),
                };
            }
        });
    }

    /**
     * GET, no CSRF, array data; `periods` and `contexts` are label => value maps.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => false,
            'method'             => 'GET',
            'allow_extra_fields' => true,
            'periods'            => [],
            'contexts'           => [],
            'context_required'   => false,
            'extra'              => [],
            'type_domain'        => 'messages',
            'translation_domain' => NowoReportKitBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('periods', 'array');
        $resolver->setAllowedTypes('contexts', 'array');
        $resolver->setAllowedTypes('context_required', 'bool');
        $resolver->setAllowedTypes('extra', 'array');
        $resolver->setAllowedTypes('type_domain', 'string');
    }

    /**
     * Rootless: the field names are the query keys.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
