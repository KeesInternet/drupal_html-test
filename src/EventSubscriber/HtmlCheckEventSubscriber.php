<?php
namespace Drupal\html_checker\EventSubscriber;

/**
 * @file
 * Contains \Drupal\my_event_subscriber\EventSubscriber\MyEventSubscriber.
 */

use Drupal;
use DOMDocument;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\html_checker\TestHtml;

/**
 * Event Subscriber MyEventSubscriber.
 */
class HtmlCheckEventSubscriber implements EventSubscriberInterface
{

    protected $testHtml;

    /**
     * Code that should be triggered on event specified
     */
    public function onRespond(FilterResponseEvent $event)
    {

        if (isset($_GET['test_html']) && 1 == \Drupal::currentUser()->id()) {
            $config = \Drupal::config('html_checker.settings');
            $parameters = array(
              'accessibility_webservice_id' => $config->get('accessibility_webservice_id'),
              'accessibility_guides' => $config->get('accessibility_guides'),
              'check_nav_active_state' => $config->get('check_nav_active_state'),
              'check_meta_msapplication' => $config->get('check_meta_msapplication'),
              'check_meta_og' => $config->get('check_meta_og'),
              'check_form_validation_classes' => $config->get('check_form_validation_classes'),
              'test_pagespeed' => $config->get('test_pagespeed')
            );
            new TestHtml($parameters);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        // For this example I am using KernelEvents constants (see below a full list).
        $events[KernelEvents::RESPONSE][] = ['onRespond'];
        return $events;
    }
}
