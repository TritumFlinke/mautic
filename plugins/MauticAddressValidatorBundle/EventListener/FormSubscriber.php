<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticAddressValidatorBundle\EventListener;

use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Event as Events;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticAddressValidatorBundle\MauticAddressValidatorEvents;
use Mautic\FormBundle\Event\SubmissionEvent;


/**
 * Class FormSubscriber.
 */
class FormSubscriber extends CommonSubscriber
{

    protected $leadModel;

    /**
     * FormSubscriber constructor.
     *
     * @param LeadModel $leadModel
     */
    public function __construct(LeadModel $leadModel)
    {
        $this->leadModel = $leadModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::FORM_ON_BUILD => ['onFormBuilder', 0],
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit', 0],
        ];
    }

    /**
     * Trigger campaign event for when a form is submitted.
     *
     * @param SubmissionEvent $event
     */
    public function onFormSubmit(SubmissionEvent $event)
    {
        $form = $event->getSubmission()->getForm();
        $fields = $form->getFields();
        $lead = $event->getLead();
        $props = [];

        foreach ($event->getFields() as $field) {
            if ($field['type'] == 'plugin.addressvalidator') {
                $addressValidatorFieldAlias = $field['alias'];
                $data = $event->getRequest()->get('mauticform')[$addressValidatorFieldAlias];
                /** @var \Mautic\FormBundle\Entity\Field $f */

                if (!empty($data)) {
                    foreach ($fields as $f) {
                        if ($f->getAlias() == $addressValidatorFieldAlias) {
                            $props = [];
                            foreach ($f->getProperties() as $key => $property) {
                                if (strpos($key, 'label') !== false || strpos($key, 'leadField') !== false) {
                                    $newKey = strtolower(str_ireplace(['label', 'leadField'], ['', ''], $key));
                                    if ($newKey) {
                                        $props[$newKey][str_ireplace($newKey, '', $key)] = $property;
                                    }
                                }
                            }
                        }
                    }
                    foreach ($data as $key => $value) {
                        if (in_array($key, array_keys($props))) {
                            $matchLeadField = $props[$key]['leadField'];
                            if ($matchLeadField) {
                                $var = 'set'.ucfirst($matchLeadField);
                                $lead->$var($value);
                            }
                        }
                    }
                    if (!empty($lead->getChanges())) {
                        $this->leadModel->saveEntity($lead);
                    }
                }
            }
        }
    }

    /**
     * Add a lead generation action to available form submit actions.
     *
     * @param FormBuilderEvent $event
     */
    public function onFormBuilder(FormBuilderEvent $event)
    {
        $action = [
            'label' => 'mautic.plugin.field.addressvalidator',
            'formType' => 'addressvalidator',
            'template' => 'MauticAddressValidatorBundle:SubscribedEvents\Field:addressvalidator.html.php',
            'builderOptions' => [
                'addLeadFieldList' => false,
                'addIsRequired' => false,
                'addDefaultValue' => false,
                'addSaveResult' => true,
                'addShowLabel' => true,
                'addHelpMessage' => false,
                'addLabelAttributes' => false,
                'addInputAttributes' => false,
                'addBehaviorFields' => false,
                'addContainerAttributes' => false,
                'allowCustomAlias' => true,
                'labelText' => false,
            ],
        ];

        $event->addFormField('plugin.addressvalidator', $action);
    }

}
