<?php

namespace Haeretici\FirewallBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class FirewallSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enable_rate_limiting', CheckboxType::class, [
                'label' => 'Enable rate limiting',
                'required' => false,
                'help' => 'When enabled, excessive requests from the same IP are temporarily banned.',
            ])
            ->add('rate_limiting_window', IntegerType::class, [
                'label' => 'Window',
                'attr' => ['min' => 60, 'max' => 3600, 'class' => 'form-control'],
                'help' => 'Time window used to count requests.',
            ])
            ->add('rate_limiting_max_requests', IntegerType::class, [
                'label' => 'Max requests',
                'attr' => ['min' => 1, 'max' => 100, 'class' => 'form-control'],
                'help' => 'Maximum requests allowed within the window.',
            ])
            ->add('rate_limiting_min_response_time', NumberType::class, [
                'label' => 'Min response time',
                'attr' => ['min' => 0, 'max' => 1, 'step' => 0.001, 'class' => 'form-control'],
                'help' => 'Requests faster than this are treated as automated.',
            ])
            ->add('rate_limiting_ban_duration', IntegerType::class, [
                'label' => 'Ban duration',
                'attr' => ['min' => 0, 'max' => 86400, 'class' => 'form-control'],
                'help' => 'How long an IP stays banned after exceeding the limit.',
            ])
            ->add('challenge_enabled_for_non_bots', CheckboxType::class, [
                'label' => 'Enable challenge',
                'required' => false,
                'help' => 'Serve a JavaScript proof-of-work challenge to unverified browsers.',
            ])
            ->add('challenge_ttl', IntegerType::class, [
                'label' => 'Challenge TTL',
                'attr' => ['min' => 60, 'max' => 600, 'class' => 'form-control'],
            ])
            ->add('challenge_verified_ttl', IntegerType::class, [
                'label' => 'Verified TTL',
                'attr' => ['min' => 300, 'max' => 3600, 'class' => 'form-control'],
                'help' => 'How long a solved challenge keeps the visitor verified.',
            ])
            ->add('challenge_secret_length', IntegerType::class, [
                'label' => 'Secret length',
                'attr' => ['min' => 8, 'max' => 32, 'class' => 'form-control'],
            ])
            ->add('challenge_dummy_ratio', NumberType::class, [
                'label' => 'Dummy ratio',
                'scale' => 2,
                'attr' => ['min' => 0, 'max' => 1, 'step' => 0.01, 'class' => 'form-control'],
            ])
            ->add('challenge_dummy_char', TextType::class, [
                'label' => 'Dummy character',
                'attr' => ['maxlength' => 1, 'class' => 'form-control'],
            ])
            ->add('bots_google_enabled', CheckboxType::class, [
                'label' => 'Googlebot',
                'required' => false,
            ])
            ->add('bots_twitter_enabled', CheckboxType::class, [
                'label' => 'Twitterbot',
                'required' => false,
            ])
            ->add('bots_facebook_enabled', CheckboxType::class, [
                'label' => 'Facebookbot',
                'required' => false,
            ])
            ->add('bots_bing_enabled', CheckboxType::class, [
                'label' => 'Bingbot',
                'required' => false,
            ])
            ->add('bots_linkedin_enabled', CheckboxType::class, [
                'label' => 'LinkedInBot',
                'required' => false,
            ])
            ->add('exemptions_paths', TextareaType::class, [
                'label' => 'Exempt paths',
                'required' => false,
                'attr' => [
                    'placeholder' => "/_fragment*\n/media/*\n*.css",
                ],
                'help' => 'One fnmatch pattern per line. Matching paths skip challenges and rate limiting checks.',
            ])
            ->add('honeypot_enabled', CheckboxType::class, [
                'label' => 'Enable traps',
                'required' => false,
                'help' => 'Instantly ban IPs that request known scanner or exploit paths.',
            ])
            ->add('honeypot_ban_duration', IntegerType::class, [
                'label' => 'Trap ban duration',
                'attr' => ['min' => 0, 'max' => 86400, 'class' => 'form-control'],
            ])
            ->add('honeypot_paths', TextareaType::class, [
                'label' => 'Trap paths',
                'required' => false,
                'attr' => [
                    'placeholder' => "*/wp-admin*\n*/wp-login.php\n*/.env",
                ],
                'help' => 'One fnmatch pattern per line. Any match triggers an immediate ban.',
            ])
        ;
    }
}