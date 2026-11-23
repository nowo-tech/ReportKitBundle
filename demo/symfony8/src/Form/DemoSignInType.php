<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Demo sign-in (no password: demo users only): pick alice or bob. CSRF-protected POST.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class DemoSignInType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('user', ChoiceType::class, [
                'label'   => 'Demo user',
                'help'    => 'alice reports on both projects and may open "Orders by status"; bob only on Beta app.',
                'choices' => ['alice' => 'alice', 'bob' => 'bob'],
                'translation_domain' => false,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'demo_sign_in']);
    }

    public function getBlockPrefix(): string
    {
        return 'demo_sign_in';
    }
}
