<?php

namespace Drupal\external_form_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;

class ExternalApiForm extends FormBase {

  public function getFormId() {
    return 'external_api_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#required' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#description' => $this->t('At least 10 characters.'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send to API'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Name must be alphabetic and at least 2 characters.
    $name = trim($form_state->getValue('name'));
    if (!preg_match('/^[a-zA-Z\s]{2,}$/', $name)) {
      $form_state->setErrorByName('name', $this->t('Name must contain only letters and be at least two characters long.'));
    }

    // Email must be valid.
    if (!filter_var($form_state->getValue('email'), FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }

    // Message must be at least 10 characters.
    if (strlen(trim($form_state->getValue('message'))) < 10) {
      $form_state->setErrorByName('message', $this->t('Message must be at least 10 characters long.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->cleanValues()->getValues();
    $client = \Drupal::httpClient();
    $endpoint = 'https://httpbin.org/post'; // Test echo endpoint

    try {
      $response = $client->post($endpoint, [
        'json' => [
          'name' => $values['name'],
          'email' => $values['email'],
          'message' => $values['message'],
          'user_id' => $this->currentUser()->id(),
        ],
        'timeout' => 5,
      ]);

      if ($response->getStatusCode() === 200) {
        $this->messenger()->addMessage($this->t('Form successfully sent to API.'));
      }
      else {
        $this->messenger()->addError($this->t('API returned status @code.', ['@code' => $response->getStatusCode()]));
      }
    }
    catch (RequestException $e) {
      watchdog_exception('external_form_test', $e);
      $this->messenger()->addError($this->t('Error sending to API: @msg', ['@msg' => $e->getMessage()]));
    }
  }

}
