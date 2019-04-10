<?php
namespace Drupal\html_checker\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class HtmlCheckerSettingsForm.
 *
 * @package Drupal\html_checker\Form
 */
class HtmlCheckerSettingsForm extends ConfigFormBase {

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
        $form['accessibility_guides'] = [
          '#type' => 'select',
          '#title' => $this->t('Accessibility guides'),
          '#description' => $this->t('WCAG2-AA by default!'),
          '#default_value' => $config->get('accessibility_guides'),
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
        ->save();
    }
}
