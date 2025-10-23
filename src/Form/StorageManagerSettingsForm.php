<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Configuration form for Storage Manager settings.
 */
class StorageManagerSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'storage_manager_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['storage_manager.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('storage_manager.settings');

    $form['self_service'] = [
      '#type' => 'details',
      '#title' => $this->t('Member self-service'),
      '#open' => TRUE,
      '#weight' => -10,
      '#tree' => TRUE,
    ];

    $claim_url = Url::fromRoute('storage_manager.claim');
    $dashboard_url = Url::fromRoute('storage_manager.member_dashboard');
    $form['self_service']['links'] = [
      '#type' => 'item',
      '#title' => $this->t('Self-service URLs'),
      '#markup' => implode('<br>', [
        Link::fromTextAndUrl($this->t('Claim storage: @url', ['@url' => $claim_url->toString()]), $claim_url)->toString(),
        Link::fromTextAndUrl($this->t('Manage storage: @url', ['@url' => $dashboard_url->toString()]), $dashboard_url)->toString(),
      ]),
      '#description' => $this->t('Share these links with members so they can claim or release their storage assignments.'),
    ];

    $form['self_service']['release_confirmation_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Self-release confirmation statement'),
      '#default_value' => $config->get('release_confirmation_message') ?: '',
      '#description' => $this->t('Displayed to members when they release their storage. Members must agree to this statement before the release is processed.'),
      '#required' => TRUE,
    ];

    $form['self_service']['release_photo_verification'] = [
      '#type' => 'radios',
      '#title' => $this->t('Photo verification on self-release'),
      '#options' => [
        'disabled' => $this->t('Disabled'),
        'optional' => $this->t('Optional'),
        'required' => $this->t('Required'),
      ],
      '#default_value' => $config->get('release_photo_verification') ?? 'disabled',
      '#description' => $this->t('Ask the member to upload a photo of the cleared-out space when they self-release their unit.'),
    ];

    $form['claim_agreement'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Storage claim agreement'),
      '#format' => $config->get('claim_agreement.format') ?: 'basic_html',
      '#default_value' => $config->get('claim_agreement.value') ?: '',
      '#description' => $this->t('The agreement text members must accept when claiming a storage unit.'),
      '#allowed_formats' => ['basic_html', 'full_html'],
    ];

    $form['stripe'] = [
      '#type' => 'details',
      '#title' => $this->t('Stripe billing'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['stripe']['enable_billing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Stripe billing'),
      '#default_value' => $config->get('stripe.enable_billing'),
      '#description' => $this->t('Enable integration with Stripe for recurring subscription billing.'),
    ];

    $form['stripe']['default_price_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Stripe price ID'),
      '#default_value' => $config->get('stripe.default_price_id'),
      '#description' => $this->t('Enter the default Stripe price ID (e.g., price_12345...). This can be overridden on individual storage assignments.'),
      '#states' => [
        'visible' => [
          ':input[name="stripe[enable_billing]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['violation'] = [
      '#type' => 'details',
      '#title' => $this->t('Violation defaults'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['violation']['default_daily_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Default violation daily rate'),
      '#default_value' => $config->get('violation.default_daily_rate') ?? '0.00',
      '#step' => '0.01',
      '#min' => '0',
      '#description' => $this->t('Base daily charge applied when a violation is active. Individual assignments can override this amount.'),
    ];

    $form['violation']['grace_period'] = [
      '#type' => 'number',
      '#title' => $this->t('Violation grace period (hours)'),
      '#default_value' => $config->get('violation.grace_period') ?? 48,
      '#min' => '0',
      '#description' => $this->t('The number of hours after a violation is recorded before daily charges begin to accrue. A warning email is sent immediately when the violation is recorded.'),
    ];

    $events = $this->getNotificationEvents();
    $enabled = $config->get('notifications.enabled_events') ?? [];
    $default_enabled = [];
    foreach ($enabled as $item) {
      $default_enabled[$item] = $item;
    }
    $templates = $config->get('notifications.templates') ?? [];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Email notifications'),
      '#open' => TRUE,
    ];

    $form['notifications']['enabled_events'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Send notifications for'),
      '#options' => array_map(fn($info) => $info['label'], $events),
      '#default_value' => $default_enabled,
      '#description' => $this->t('Select which events should trigger outbound emails.'),
    ];

    $form['notifications']['recipients'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional recipient emails'),
      '#description' => $this->t('Optional comma-separated list of staff emails that receive every notification.'),
      '#default_value' => $config->get('notifications.recipients') ?: '',
    ];

    $form['notifications']['tokens'] = [
      '#type' => 'item',
      '#title' => $this->t('Available tokens'),
      '#markup' => implode('<br>', [
        '<code>[member_name]</code>',
        '<code>[unit_id]</code>',
        '<code>[storage_type]</code>',
        '<code>[monthly_cost]</code>',
        '<code>[assignment_start]</code>',
        '<code>[assignment_end]</code>',
        '<code>[release_date]</code>',
        '<code>[violation_daily_rate]</code>',
        '<code>[violation_total_due]</code>',
        '<code>[violation_start]</code>',
        '<code>[violation_resolved]</code>',
        '<code>[site_name]</code>',
      ]),
    ];

    foreach ($events as $key => $info) {
      $template = $templates[$key] ?? ['subject' => '', 'body' => ''];
      $form['notifications'][$key] = [
        '#type' => 'details',
        '#title' => $info['label'],
        '#description' => $info['description'],
        '#open' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="enabled_events[' . $key . ']"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['notifications'][$key]['template_' . $key . '_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $template['subject'] ?? '',
        '#maxlength' => 255,
      ];

      $form['notifications'][$key]['template_' . $key . '_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $template['body'] ?? '',
        '#rows' => 8,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('storage_manager.settings');

    $self_service_settings = $form_state->getValue('self_service');
    $config->set('release_confirmation_message', $self_service_settings['release_confirmation_message']);
    $config->set('release_photo_verification', $self_service_settings['release_photo_verification']);

    $agreement = $form_state->getValue('claim_agreement');
    $config->set('claim_agreement', $agreement);

    $stripe_settings = $form_state->getValue('stripe');
    $config->set('stripe.enable_billing', $stripe_settings['enable_billing']);
    $config->set('stripe.default_price_id', $stripe_settings['default_price_id']);

    $violation_settings = $form_state->getValue('violation');
    $default_daily = $violation_settings['default_daily_rate'];
    $config->set('violation.default_daily_rate', $default_daily === '' || $default_daily === NULL ? '0.00' : number_format((float) $default_daily, 2, '.', ''));
    $config->set('violation.grace_period', (int) $violation_settings['grace_period']);

    $events = $this->getNotificationEvents();

    $enabled_values = $form_state->getValue('enabled_events') ?? [];
    $enabled = array_keys(array_filter($enabled_values));
    $config->set('notifications.enabled_events', $enabled);
    $config->set('notifications.recipients', $form_state->getValue('recipients'));

    $templates = [];
    foreach ($events as $key => $info) {
      $templates[$key] = [
        'subject' => $form_state->getValue('template_' . $key . '_subject'),
        'body' => $form_state->getValue('template_' . $key . '_body'),
      ];
    }
    $config->set('notifications.templates', $templates);
    $config->save();

    parent::submitForm($form, $form_state);
  }

  protected function getNotificationEvents(): array {
    return [
      'assignment' => [
        'label' => $this->t('Assignment created'),
        'description' => $this->t('Sent when a storage assignment is created.'),
      ],
      'release' => [
        'label' => $this->t('Assignment released'),
        'description' => $this->t('Sent when a storage assignment is ended.'),
      ],
      'violation_warning' => [
        'label' => $this->t('Violation warning'),
        'description' => $this->t('Sent when a violation is first flagged.'),
      ],
      'violation_fine' => [
        'label' => $this->t('Violation fine notice'),
        'description' => $this->t('Sent when a violation accrues charges.'),
      ],
      'violation_resolved' => [
        'label' => $this->t('Violation resolved'),
        'description' => $this->t('Sent when a violation is marked resolved.'),
      ],
    ];
  }
}