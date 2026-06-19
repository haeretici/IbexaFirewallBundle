<?php

namespace Haeretici\FirewallBundle\EventListener;

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Ibexa\Contracts\AdminUi\Menu\MenuItemFactoryInterface;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Attribute;
use JMS\TranslationBundle\Model\Message;
use JMS\TranslationBundle\Translation\TranslationContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuListener implements EventSubscriberInterface, TranslationContainerInterface
{
    public const ITEM_CONTENT_GROUP_FIREWALL = 'main__content__group_firewall';
    public const ITEM_CONTENT_FIREWALL_DASHBOARD = 'main__content__firewall_dashboard';
    public const ITEM_CONTENT_FIREWALL_SETTINGS = 'main__content__firewall_settings';

    private AuthorizationCheckerInterface $authorizationChecker;
    private MenuItemFactoryInterface $menuItemFactory;

    public function __construct(
        AuthorizationCheckerInterface $authorizationChecker,
        MenuItemFactoryInterface $menuItemFactory
    ) {
        $this->authorizationChecker = $authorizationChecker;
        $this->menuItemFactory = $menuItemFactory;
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMenuEvent::MAIN_MENU => 'onMainMenuBuild'];
    }

    public function onMainMenuBuild(ConfigureMenuEvent $event): void
    {
        if (!$this->authorizationChecker->isGranted(new Attribute('haeretici_firewall', 'admin'))) {
            return;
        }

        $menu = $event->getMenu();
        $contentMenu = $menu->getChild(MainMenuBuilder::ITEM_CONTENT);
        if ($contentMenu === null) {
            return;
        }

        $firewallGroup = $this->menuItemFactory->createItem(self::ITEM_CONTENT_GROUP_FIREWALL, [
            'extras' => [
                'orderNumber' => 80,
            ],
        ]);

        $firewallGroup->addChild(
            $this->menuItemFactory->createItem(self::ITEM_CONTENT_FIREWALL_DASHBOARD, [
                'route' => 'haeretici_firewall.dashboard',
                'extras' => [
                    'orderNumber' => 10,
                    'routes' => [
                        'metrics' => 'haeretici_firewall.metrics',
                    ],
                ],
            ])
        );

        $firewallGroup->addChild(
            $this->menuItemFactory->createItem(self::ITEM_CONTENT_FIREWALL_SETTINGS, [
                'route' => 'haeretici_firewall.settings',
                'extras' => [
                    'orderNumber' => 20,
                ],
            ])
        );

        $contentMenu->addChild($firewallGroup);
    }

    public static function getTranslationMessages(): array
    {
        return [
            (new Message(self::ITEM_CONTENT_GROUP_FIREWALL, 'ibexa_menu'))->setDesc('Firewall'),
            (new Message(self::ITEM_CONTENT_FIREWALL_DASHBOARD, 'ibexa_menu'))->setDesc('Dashboard'),
            (new Message(self::ITEM_CONTENT_FIREWALL_SETTINGS, 'ibexa_menu'))->setDesc('Settings'),
        ];
    }
}