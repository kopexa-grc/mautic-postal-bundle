<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPostalBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Fields of the Postal plugin dialog: the key Postal signs its webhooks
 * with. Mautic stores them encrypted with the integration.
 */
class PostalKeysType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('jwks_url', UrlType::class, [
            'label'       => 'Postal key URL (JWKS)',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'default_protocol' => 'https',
            'attr'        => [
                'class'       => 'form-control',
                'placeholder' => 'https://postal.example.com/.well-known/jwks.json',
                'tooltip'     => 'Your Postal server publishes its signing key at /.well-known/jwks.json. Easiest option: the key is read automatically and follows key changes.',
            ],
            'constraints' => [new Url(['protocols' => ['https'], 'message' => 'Use an https:// address.'])],
        ]);

        $builder->add('public_key', TextareaType::class, [
            'label'      => 'Or: Postal public key (PEM)',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'rows'        => 5,
                'placeholder' => "-----BEGIN PUBLIC KEY-----\n…\n-----END PUBLIC KEY-----",
                'tooltip'     => 'Only if Mautic cannot reach your Postal server. Takes precedence over the key URL.',
            ],
            'constraints' => [new Callback(static function (?string $pem, ExecutionContextInterface $context): void {
                $pem = trim((string) $pem);
                if ('' !== $pem && false === @openssl_pkey_get_public($pem)) {
                    $context->buildViolation('This is not a valid public key (PEM).')->addViolation();
                }
            })],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefined(['integration']);
    }
}
