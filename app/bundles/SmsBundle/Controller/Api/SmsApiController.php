<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\SmsBundle\Controller\Api;

use FOS\RestBundle\Util\Codes;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

/**
 * Class SmsApiController.
 */
class SmsApiController extends CommonApiController
{
    use LeadAccessTrait;

    /**
     * {@inheritdoc}
     */
    public function initialize(FilterControllerEvent $event)
    {
        $this->model           = $this->getModel('sms');
        $this->entityClass     = 'Mautic\SmsBundle\Entity\Sms';
        $this->entityNameOne   = 'sms';
        $this->entityNameMulti = 'smses';

        parent::initialize($event);
    }

    /**
     * Send sms to contact.
     *
     * @param int $id     Email ID
     * @param int $leadId Contact ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function sendContactAction($id, $leadId)
    {
        $integration =  $this->get('mautic.helper.integration')->getIntegrationObject('Twilio');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return $this->notFound();
        }

        $success = 0;
        $entity  = $this->model->getEntity($id);
        if (null !== $entity) {
            if (!$this->checkEntityAccess($entity, 'view')) {
                return $this->accessDenied();
            }

            $lead = $this->checkLeadAccess($leadId, 'edit');
            if ($lead instanceof Response) {
                return $lead;
            }

            $result = $this->model->sendSms($entity, $lead, ['channel' => 'api'])[$lead->getId()];
            if (!empty($result['sent'])) {
                $success = 1;
            }

            $view = $this->view(
                [
                    'success'           => $success,
                    'status'            => $this->get('translator')->trans($result['status']),
                ],
                Codes::HTTP_OK
            );

            return $this->handleView($view);
        }

        return $this->notFound();
    }

    /**
     * Obtains a list of emails.
     *
     * @return Response
     */
    public function receiveAction()
    {
        $body = $this->request->get('Body');
        $from = $this->request->get('From');

        if ($body === 'STOP' && $this->factory->getHelper('sms')->unsubscribe($from)) {
            return new Response('<Response><Sms>You have been unsubscribed.</Sms></Response>', 200, ['Content-Type' => 'text/xml; charset=utf-8']);
        }

        // Return an empty response
        return new Response();
    }
}
