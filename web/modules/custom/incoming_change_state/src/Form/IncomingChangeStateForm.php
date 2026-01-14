<?php

declare(strict_types=1);

namespace Drupal\incoming_change_state\Form;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides a form to change the moderation state of an incoming node.
 */
final class IncomingChangeStateForm extends FormBase {

  /**
   * Content moderation information service.
   */
  private ModerationInformationInterface $moderationInformation;

  /**
   * Node storage.
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * Time service.
   */
  private TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_moderation.moderation_information'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Constructs the form.
   */
  public function __construct(ModerationInformationInterface $moderationInformation, EntityTypeManagerInterface $entityTypeManager, TimeInterface $time) {
    $this->moderationInformation = $moderationInformation;
    $this->nodeStorage = $entityTypeManager->getStorage('node');
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'incoming_change_state_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'incoming') {
      throw new AccessDeniedHttpException();
    }

    if (!$this->moderationInformation->isModeratedEntity($node)) {
      throw new AccessDeniedHttpException();
    }

    // Always work with the latest revision so the current state reflects the
    // newest change, even if the default revision is still published.
    $latestRevisionId = $this->nodeStorage->getLatestRevisionId($node->id());
    $latestNode = $latestRevisionId ? $this->nodeStorage->loadRevision($latestRevisionId) : NULL;
    $activeNode = $latestNode instanceof NodeInterface ? $latestNode : $node;

    $workflow = $this->moderationInformation->getWorkflowForEntity($node);
    if ($workflow === NULL) {
      throw new AccessDeniedHttpException();
    }

    $typePlugin = $workflow->getTypePlugin();
    $stateConfigurations = $typePlugin->getConfiguration()['states'] ?? [];
    $stateOptions = $this->buildStateOptions($typePlugin->getStates(), $stateConfigurations);
    $currentState = $activeNode->get('moderation_state')->value ?? '';
    $currentStateLabel = $stateOptions[$currentState] ?? $currentState;
    $lastState = $stateOptions ? array_key_last($stateOptions) : '';

    $form['current_state'] = [
      '#type' => 'item',
      '#title' => $this->t('Current state'),
      '#markup' => $currentStateLabel ?: $this->t('Not set'),
    ];

    if ($currentState !== '' && $currentState === $lastState && !$this->currentUser()->hasRole('administrator')) {
      $form_state->set('incoming_change_state_node', $activeNode);
      return $form;
    }

    $form['new_state'] = [
      '#type' => 'select',
      '#title' => $this->t('New state'),
      '#options' => $stateOptions,
      '#default_value' => $currentState,
      '#required' => TRUE,
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Explain why this state change is needed. This will be stored as the revision log message.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Change state'),
    ];

    $form_state->set('incoming_change_state_node', $activeNode);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $selectedState = (string) $form_state->getValue('new_state');
    $options = $form['new_state']['#options'] ?? [];
    if ($selectedState === '' || !isset($options[$selectedState])) {
      $form_state->setErrorByName('new_state', $this->t('Select a valid state.'));
    }

    $reason = trim((string) $form_state->getValue('reason'));
    if ($reason === '' || strlen($reason) < 10) {
      $form_state->setErrorByName('reason', $this->t('Please provide a reason of at least 10 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $form_state->get('incoming_change_state_node');
    if (!$node instanceof NodeInterface) {
      throw new AccessDeniedHttpException();
    }

    $newState = (string) $form_state->getValue('new_state');
    $reason = trim((string) $form_state->getValue('reason'));

    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(TRUE);
    $node->set('moderation_state', $newState);
    $node->setRevisionTranslationAffected(TRUE);
    $node->setRevisionUserId($this->currentUser()->id());
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionLogMessage($reason !== '' ? $reason : $this->t('State changed via Change state form.'));
    $node->save();

    $stateLabels = $form['new_state']['#options'] ?? [];
    $this->messenger()->addStatus($this->t('State changed to @state.', ['@state' => $stateLabels[$newState] ?? $newState]));
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Builds a sorted list of state labels keyed by state ID.
   *
   * @param array $states
   *   Workflow state definitions.
   *
   * @return array<string, string>
   *   The sorted options array.
   */
  private function buildStateOptions(array $states, array $configurations): array {
    $sortable = [];
    foreach ($states as $stateId => $state) {
      $sortable[] = [
        'id' => $stateId,
        'label' => $state->label(),
        'weight' => isset($configurations[$stateId]['weight']) ? (int) $configurations[$stateId]['weight'] : 0,
      ];
    }

    usort($sortable, static function (array $a, array $b): int {
      return $a['weight'] <=> $b['weight'];
    });

    $options = [];
    foreach ($sortable as $item) {
      $options[$item['id']] = $item['label'];
    }

    return $options;
  }

}
