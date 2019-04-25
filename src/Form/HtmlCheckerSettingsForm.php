<?php
namespace Drupal\html_checker\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class HtmlCheckerSettingsForm.
 *
 * @package Drupal\html_checker\Form
 */
class HtmlCheckerSettingsForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return ['html_checker.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'settings';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('html_checker.settings');
        $form['accessibility_webservice_id'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Accessibility checker Webservice ID'),
          '#description' => $this->t('Register on https://achecker.ca/register.php'),
          '#default_value' => $config->get('accessibility_webservice_id'),
        ];
        $accessibility_guides = $config->get('accessibility_guides');
        $form['accessibility_guides'] = [
          '#type' => 'select',
          '#title' => $this->t('Accessibility guides'),
          '#description' => $this->t('WCAG2-AA by default!'),
          '#default_value' => ($accessibility_guides? $accessibility_guides : 'WCAG2-AA'),
          '#multiple' => true,
          '#options' => [
            'BITV1' => $this->t('bitv-1.0-(level-2)'),
            '508' => $this->t('section-508'),
            'STANCA' => $this->t('stanca-act'),
            'WCAG1-A' => $this->t('wcag-1.0-(level-a)'),
            'WCAG1-AA' => $this->t('wcag-1.0-(level-aa)'),
            'WCAG1-AAA' => $this->t('wcag-1.0-(level-aaa)'),
            'WCAG2-A' => $this->t('wcag-2.0-l1'),
            'WCAG2-AA' => $this->t('wcag-2.0-l2'),
            'WCAG2-AAA' => $this->t('wcag-2.0-l3')
          ],
        ];
        $check_nav_active_state = $config->get('check_nav_active_state');
        $form['check_nav_active_state'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Check navigation active state'),
          '#description' => $this->t('Checks for \'active\' class on li- / a-tags.').'<br />'.$this->t('Assuming main navigation is in header-tag!').'<br />'.$this->t('Ul-tag for subnav must have class / ID \'nav\'!'),
          '#default_value' => (is_numeric($check_nav_active_state)? $check_nav_active_state : 1),
          '#options' => array(
              1 => t('yes'),
              0 => t('no')
          )
        ];
        $check_meta_msapplication = $config->get('check_meta_msapplication');
        $form['check_meta_msapplication'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Check \'msapplication\' metatags'),
          '#description' => $this->t(''),
          '#default_value' => (is_numeric($check_meta_msapplication)? $check_meta_msapplication : 1),
          '#options' => array(
              1 => t('yes'),
              0 => t('no')
          )
        ];
        $check_meta_msapplication = $config->get('check_meta_og');
        $form['check_meta_og'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Check \'OG:\' metatags'),
          '#description' => $this->t(''),
          '#default_value' => (is_numeric($check_meta_msapplication)? $check_meta_msapplication : 1),
          '#options' => array(
              1 => t('yes'),
              0 => t('no')
          )
        ];
        $check_form_validation_classes = $config->get('check_form_validation_classes');
        $form['check_form_validation_classes'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Check (Dutch) jQuery validation classes on forms'),
          '#description' => $this->t('Checks for classes: required, iban, email, phoneNL, postalcodeNL and mobileNL'),
          '#default_value' => (is_numeric($check_form_validation_classes)? $check_form_validation_classes : 1),
          '#options' => array(
              1 => t('yes'),
              0 => t('no')
          )
        ];
        $test_pagespeed = $config->get('test_pagespeed');
        $form['test_pagespeed'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Test page speed'),
          '#description' => $this->t('Test page speed according to Google PageSpeed Insights'),
          '#default_value' => (is_numeric($test_pagespeed)? $test_pagespeed : 1),
          '#options' => array(
              1 => t('yes'),
              0 => t('no')
          )
        ];
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitForm($form, $form_state);
        $this->config('html_checker.settings')
        ->set('accessibility_webservice_id', $form_state->getValue('accessibility_webservice_id'))
        ->set('accessibility_guides', $form_state->getValue('accessibility_guides'))
        ->set('check_nav_active_state', $form_state->getValue('check_nav_active_state'))
        ->set('check_meta_msapplication', $form_state->getValue('check_meta_msapplication'))
        ->set('check_form_validation_classes', $form_state->getValue('check_form_validation_classes'))
        ->set('check_meta_og', $form_state->getValue('check_meta_og'))
        ->save();
    }
}
