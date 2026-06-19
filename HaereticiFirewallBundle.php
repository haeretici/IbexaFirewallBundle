<?php

namespace Haeretici\FirewallBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class HaereticiFirewallBundle extends Bundle
{
    public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container)
    {
        parent::build($container);
        $eZExtension = $container->getExtension('ibexa');
        $eZExtension->addPolicyProvider(new Security\PolicyProvider());
    }
}
