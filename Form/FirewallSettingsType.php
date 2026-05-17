<?php

namespace Ne0Heretic\FirewallBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class FirewallSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rate_limiting_window', IntegerType::class, [
                'label' => 'Rate Limit Window (seconds)',
                'attr' => ['min' => 60, 'max' => 3600],
            ])
            ->add('rate_limiting_max_requests', IntegerType::class, [
                'label' => 'Max Requests',
                'attr' => ['min' => 1, 'max' => 100],
            ])
            ->add('rate_limiting_min_response_time', NumberType::class, [
                'label' => 'Min Response Time',
                'attr' => ['min' => 0, 'max' => 1, 'step' => 0.001],
            ])
            ->add('rate_limiting_ban_duration', IntegerType::class, [
                'label' => 'Ban Duration (seconds)',
                'attr' => ['min' => 0, 'max' => 86400],
            ])
            ->add('challenge_ttl', IntegerType::class, [
                'label' => 'Challenge TTL (seconds)',
                'attr' => ['min' => 60, 'max' => 600],
            ])
            ->add('challenge_verified_ttl', IntegerType::class, [
                'label' => 'Verified TTL (seconds)',
                'attr' => ['min' => 300, 'max' => 3600],
            ])
            ->add('challenge_secret_length', IntegerType::class, [
                'label' => 'Secret Length (bytes)',
                'attr' => ['min' => 8, 'max' => 32],
            ])
            ->add('challenge_dummy_ratio', NumberType::class, [
                'label' => 'Dummy Ratio (0.0-1.0)',
                'scale' => 2,
                'attr' => ['min' => 0, 'max' => 1, 'step' => 0.01],
            ])
            ->add('challenge_dummy_char', TextType::class, [
                'label' => 'Dummy Character',
                'attr' => ['maxlength' => 1],
            ])
            ->add('challenge_enabled_for_non_bots', CheckboxType::class, [
                'label' => 'Enable Challenge for Non-Bot Traffic (Beta)',
                'required' => false
            ])
            ->add('bots_google_enabled', CheckboxType::class, [
                'label' => 'Validate Googlebot',
                'required' => false
            ])
            ->add('bots_twitter_enabled', CheckboxType::class, [
                'label' => 'Validate Twitterbot',
                'required' => false
            ])
            ->add('bots_facebook_enabled', CheckboxType::class, [
                'label' => 'Validate Facebookbot',
                'required' => false
            ])
            ->add('bots_bing_enabled', CheckboxType::class, [
                'label' => 'Validate Bingbot',
                'required' => false
            ])
            ->add('bots_linkedin_enabled', CheckboxType::class, [
                'label' => 'Validate LinkedInBot',
                'required' => false
            ])
            ->add('enable_rate_limiting', CheckboxType::class, [
                'label' => 'Enable Rate Limiting',
                'required' => false
            ])
            ->add('exemptions_paths', TextType::class, [
                'label' => 'Exempt Paths (comma-separated fnmatch patterns)',
                'attr' => ['placeholder' => '/_fragment*'],
                'help' => 'Paths exempt from challenges, e.g. static assets.',
            ])
            ->add('honeypot_enabled', CheckboxType::class, [
                'label' => 'Enable Honeypot Traps',
                'required' => false
            ])
            ->add('honeypot_ban_duration', IntegerType::class, [
                'label' => 'Trap Ban Duration (seconds)',
                'attr' => ['min' => 0, 'max' => 86400],
            ])
            ->add('honeypot_paths', TextType::class, [
                'label' => 'Trap Paths (comma-separated fnmatch patterns)',
                'attr' => ['placeholder' => '*/wp-admin*,*/wp-login.php,*/.env,*/.git/*,*/config.json,*/phpinfo.php,*/xmlrpc.php'],
                'help' => 'Common WordPress scanner traps.',
            ])

        ;
    }
}