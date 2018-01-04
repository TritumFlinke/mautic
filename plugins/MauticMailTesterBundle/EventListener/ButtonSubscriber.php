<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticMailTesterBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Templating\Helper\ButtonHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticMailTesterBundle\Integration\MailTesterIntegration;

class ButtonSubscriber extends CommonSubscriber
{
    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * ButtonSubscriber constructor.
     *
     * @param IntegrationHelper $helper
     */
    public function __construct(IntegrationHelper $integrationHelper)
    {
        $this->integrationHelper = $integrationHelper;
    }

    public static function getSubscribedEvents()
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    /**
     * @param CustomButtonEvent $event
     */
    public function injectViewButtons(CustomButtonEvent $event)
    {
        /** @var MailTesterIntegration $myIntegration */
        $myIntegration = $this->integrationHelper->getIntegrationObject('MailTester');

        if (false === $myIntegration || !$myIntegration->getIntegrationSettings()->getIsPublished()) {
            return;
        }

        if (0 === strpos($event->getRoute(), 'mautic_email_action') && $event->getRequest()->get('objectAction') == 'view') {
            $mailTestRoute = $this->router->generate(
                'mautic_plugin_mail_tester_action',
                [
                    'objectAction' => 'sendMailTest',
                    'objectId'     => $event->getRequest()->get('objectId'),
                ]
            );

            $event->addButton(
                [
                    'attr' => [
                        'href'        => $mailTestRoute,
                        'data-toggle' => '',
                        'target'      => '_blank',
                    ],
                    'btnText'   => $this->translator->trans('plugin.mail.tester.test'),
                    'iconClass' => 'fa fa-external-link',
                    'priority'  => 5,
                ],
                ButtonHelper::LOCATION_PAGE_ACTIONS
            );
        }
    }
}
