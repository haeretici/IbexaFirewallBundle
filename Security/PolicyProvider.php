<?php

namespace Ne0Heretic\FirewallBundle\Security;

use Ibexa\Bundle\Core\DependencyInjection\Configuration\ConfigBuilderInterface;
use Ibexa\Bundle\Core\DependencyInjection\Security\PolicyProvider\PolicyProviderInterface;

class PolicyProvider implements PolicyProviderInterface
{
    public function addPolicies(ConfigBuilderInterface $configBuilder)
    {
        $configBuilder->addConfig([
             'ne0heretic_firewall' => [
                 'admin' => null,
                 'content' => ['view'],
             ],
         ]);
    }
}
