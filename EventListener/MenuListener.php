<?php

namespace Ne0Heretic\FirewallBundle\EventListener;

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Attribute;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuListener implements EventSubscriberInterface
{
    /**
     * @var AuthorizationCheckerInterface
     */
    private $authorizationChecker;

    public function __construct(AuthorizationCheckerInterface $authorizationChecker)
    {
        $this->authorizationChecker = $authorizationChecker;
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMenuEvent::MAIN_MENU => 'onMainMenuBuild'];
    }

    public function onMainMenuBuild(ConfigureMenuEvent $event): void
    {
        if (!$this->authorizationChecker->isGranted(new Attribute('ne0heretic_firewall', 'admin'))) {
            return;
        }
        $menu = $event->getMenu();
        $customMenuItem = $menu[MainMenuBuilder::ITEM_CONTENT]->addChild(
            'ne0heretic_firewall',
            [
                'route' => 'ne0heretic_firewall.dashboard',
                'extras' => [
                    'orderNumber' => 100,
                ],
            ],
        )->setLabel('Firewall');
    }
}
