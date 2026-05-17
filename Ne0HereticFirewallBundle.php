<?php

namespace Ne0Heretic\FirewallBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class Ne0HereticFirewallBundle extends Bundle
{
    public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container)
    {
        parent::build($container);
        $eZExtension = $container->getExtension('ibexa');
        $eZExtension->addPolicyProvider(new Security\PolicyProvider());
    }
}
