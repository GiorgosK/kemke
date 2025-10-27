<?php

namespace Drupal\ajax_form_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Simple AJAX contact form that submits to an external API.
 */
class AjaxForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ajax_form_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Wrap the entire form so the AJAX callback can refresh it in place.
    $form['#prefix'] = '<div id="ajax-form-test-wrapper">';
    $form['#suffix'] = '</div>';

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#maxlength' => 64,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#description' => $this->t('Provide at least 10 characters.'),
      '#maxlength' => 1000,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'ajax-form-test-wrapper',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('name'));
    if (strlen($name) < 2) {
      $form_state->setErrorByName('name', $this->t('Name must be at least two characters.'));
    }

    $email = $form_state->getValue('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Enter a valid email address.'));
    }

    $message = trim((string) $form_state->getValue('message'));
    if (strlen($message) < 10) {
      $form_state->setErrorByName('message', $this->t('Message must be at least ten characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->cleanValues()->getValues();

    $payload = [
      'name' => $values['name'],
      'email' => $values['email'],
      'message' => $values['message'],
      'uid' => $this->currentUser()->id(),
    ];

    $client = \Drupal::httpClient();
    $endpoint = 'https://httpbin.org/post';

    try {
      $response = $client->post($endpoint, [
        'json' => $payload,
        'timeout' => 5,
      ]);

      if ($response->getStatusCode() === 200) {
        $this->messenger()->addStatus($this->t('Your message was sent successfully.'));
        $form_state->setRebuild(TRUE);
        $form_state->setValues([]);
        $form_state->setUserInput([]);
      }
      else {
        $this->messenger()->addError($this->t('The API returned an unexpected response (@code).', [
          '@code' => $response->getStatusCode(),
        ]));
        $form_state->setRebuild(TRUE);
      }
    }
    catch (RequestException $exception) {
      $this->messenger()->addError($this->t('Unable to submit the form: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * AJAX callback returning the rebuilt form.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

}
