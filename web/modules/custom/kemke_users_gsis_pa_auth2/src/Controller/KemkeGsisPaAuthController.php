<?php

declare(strict_types=1);

namespace Drupal\kemke_users_gsis_pa_auth2\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class KemkeGsisPaAuthController extends ControllerBase {

  private const OAUTH_PLUGIN_ID = 'kemke_gsis_pa';
  private const SESSION_DESTINATION_KEY = 'kemke_users_gsis_pa_auth2.destination';
  private const MOCK_CODES_STATE_KEY = 'kemke_users_gsis_pa_auth2.mock.codes';
  private const MOCK_TOKENS_STATE_KEY = 'kemke_users_gsis_pa_auth2.mock.tokens';

  public function __construct(
    private readonly Oauth2ClientServiceInterface $oauth2ClientService,
    private readonly ClientInterface $httpClient,
    private readonly RequestStack $requestStack,
    private readonly MessengerInterface $messengerService,
    private readonly AccountProxyInterface $currentAccount,
    private readonly StateInterface $state,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('oauth2_client.service'),
      $container->get('http_client'),
      $container->get('request_stack'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('state'),
    );
  }

  public function login(): RedirectResponse {
    $settings = $this->getGlueSettings();
    if (!$settings['enabled']) {
      $this->messengerService->addError($this->t('GSIS login is currently disabled.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    if ($this->currentAccount->isAuthenticated()) {
      return new RedirectResponse('/incoming');
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $destination = (string) $request->query->get('destination', '');
      if ($destination !== '' && $destination[0] === '/') {
        $request->getSession()->set(self::SESSION_DESTINATION_KEY, $destination);
      }
    }

    // Force a fresh auth flow for deterministic testing.
    $this->oauth2ClientService->clearAccessToken(self::OAUTH_PLUGIN_ID);

    // For authorization_code this triggers a redirect by throwing
    // AuthCodeRedirect, handled by Drupal's kernel.
    $token = $this->oauth2ClientService->getAccessToken(self::OAUTH_PLUGIN_ID, NULL);
    if ($token !== NULL) {
      return new RedirectResponse(Url::fromRoute('kemke_users_gsis_pa_auth2.finalize')->toString());
    }

    $this->messengerService->addError($this->t('Unable to start GSIS OAuth2 flow. Check OAuth2 client credentials and plugin status.'));
    return new RedirectResponse(Url::fromRoute('user.login')->toString());
  }

  public function finalize(): RedirectResponse {
    $token = $this->oauth2ClientService->retrieveAccessToken(self::OAUTH_PLUGIN_ID);
    if ($token === NULL) {
      $this->messengerService->addError($this->t('GSIS login failed: missing access token.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $client = $this->oauth2ClientService->getClient(self::OAUTH_PLUGIN_ID);
    $userinfo_url = $client->getResourceUri();

    try {
      $response = $this->httpClient->request('GET', $userinfo_url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token->getToken(),
          'Accept' => 'application/xml, text/xml, */*',
        ],
        'timeout' => 20,
      ]);
    }
    catch (\Throwable $throwable) {
      $this->getLogger('kemke_users_gsis_pa_auth2')->error('Userinfo request failed: @message', ['@message' => $throwable->getMessage()]);
      $this->messengerService->addError($this->t('GSIS login failed while fetching user info.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $xml_string = trim((string) $response->getBody());
    if ($xml_string === '') {
      $this->messengerService->addError($this->t('GSIS login failed: empty user info response.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_string($xml_string, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$xml instanceof \SimpleXMLElement) {
      $this->messengerService->addError($this->t('GSIS login failed: invalid XML response.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $details = [
      'username' => $this->extractXmlValue($xml, ['USERNAME', 'userid', 'username', 'userName']),
      'first_name' => $this->extractXmlValue($xml, ['ONOMA', 'FIRSTNAME', 'firstname', 'name']),
      'last_name' => $this->extractXmlValue($xml, ['EPONYMO', 'LASTNAME', 'lastname', 'surname']),
      'afm' => $this->extractXmlValue($xml, ['AFM', 'taxid', 'TIN']),
    ];

    if ($details['username'] === NULL) {
      $this->messengerService->addError($this->t('GSIS login failed: USERNAME missing from response.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $match_result = $this->resolveUserMatch($details['username'], $details['first_name'], $details['last_name'], $details['afm']);
    $user = $match_result['user'];
    $settings = $this->getGlueSettings();

    if ($user === NULL && $settings['allow_user_creation']) {
      $user = $this->createUserFromDetails($details);
      foreach ($settings['default_roles'] as $role) {
        if (is_string($role) && $role !== '' && $user->hasRole($role) === FALSE) {
          $user->addRole($role);
        }
      }
      $user->save();
      $this->getLogger('kemke_users_gsis_pa_auth2')->notice('Created user @user from GSIS response.', ['@user' => $user->getAccountName()]);
    }

    if ($user === NULL) {
      if ($match_result['reason'] === 'afm_mismatch') {
        $this->messengerService->addError($this->t('Login denied: the AFM returned by GSIS does not match our records.'));
        return new RedirectResponse(Url::fromRoute('user.login')->toString());
      }
      $this->messengerService->addError($this->t('No matching local user account was found for GSIS username @username.', ['@username' => $details['username']]));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    if (!$user->isActive()) {
      $this->messengerService->addError($this->t('Your local account is blocked. Contact an administrator.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $this->syncUserFields($user, $details);
    $user->save();

    user_login_finalize($user);

    $request = $this->requestStack->getCurrentRequest();
    $destination = '/incoming';
    if ($request !== NULL) {
      $stored = (string) $request->getSession()->get(self::SESSION_DESTINATION_KEY, '');
      if ($stored !== '' && $stored[0] === '/') {
        $destination = $stored;
      }
      $request->getSession()->remove(self::SESSION_DESTINATION_KEY);
    }

    return new RedirectResponse($destination);
  }

  public function mockAuthorize(Request $request): array|RedirectResponse {
    $redirect_uri = (string) $request->query->get('redirect_uri', '');
    $state = (string) $request->query->get('state', '');
    $response_type = (string) $request->query->get('response_type', '');

    if ($redirect_uri === '' || $response_type !== 'code') {
      return new RedirectResponse('/user/login');
    }

    $has_mock_inputs = $request->query->has('mock_username')
      || $request->query->has('mock_first_name')
      || $request->query->has('mock_last_name')
      || $request->query->has('mock_afm')
      || $request->query->has('scenario');

    if (!$has_mock_inputs) {
      $profiles = $this->buildMockUserProfiles();
      $selected_uid = (string) $request->query->get('mock_user_uid', '');
      $selected_profile = ($selected_uid !== '' && isset($profiles[$selected_uid])) ? $profiles[$selected_uid] : NULL;

      $default_username = $selected_profile['username'] ?? 'mock.gsis.user';
      $default_first_name = $selected_profile['first_name'] ?? 'Mock';
      $default_last_name = $selected_profile['last_name'] ?? 'User';
      $default_afm = $selected_profile['afm'] ?? '999999999';

      $options = '<option value="">-- Manual entry --</option>';
      foreach ($profiles as $uid => $profile) {
        $label = sprintf(
          'uid:%s | %s (%s %s)',
          $uid,
          $profile['username'],
          $profile['first_name'],
          $profile['last_name']
        );
        $selected = ((string) $uid === $selected_uid) ? ' selected' : '';
        $options .= '<option value="' . Html::escape((string) $uid) . '"' . $selected . '>' . Html::escape($label) . '</option>';
      }

      $profiles_json = json_encode($profiles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
      if (!is_string($profiles_json)) {
        $profiles_json = '{}';
      }

      $form_html = '<form method="get" style="max-width:640px;padding:16px;border:1px solid #ddd;border-radius:8px">'
        . '<h2>Mock GSIS OAuth2 Login</h2>'
        . '<p>Select an existing Drupal user to prefill fields, then adjust values if you want to simulate mismatch/failure.</p>'
        . '<label>Drupal user<br><select id="mock_user_uid" name="mock_user_uid" style="width:100%">' . $options . '</select></label><br><br>'
        . '<label>Username<br><input id="mock_username" required name="mock_username" value="' . Html::escape((string) $default_username) . '" style="width:100%"></label><br><br>'
        . '<label>First name<br><input id="mock_first_name" required name="mock_first_name" value="' . Html::escape((string) $default_first_name) . '" style="width:100%"></label><br><br>'
        . '<label>Last name<br><input id="mock_last_name" required name="mock_last_name" value="' . Html::escape((string) $default_last_name) . '" style="width:100%"></label><br><br>'
        . '<label>AFM<br><input id="mock_afm" required name="mock_afm" value="' . Html::escape((string) $default_afm) . '" style="width:100%"></label><br><br>'
        . '<label>Scenario<br><select name="scenario" style="width:100%">'
        . '<option value="">Success</option>'
        . '<option value="deny">Deny access</option>'
        . '</select></label>'
        . '<input type="hidden" name="client_id" value="' . Html::escape((string) $request->query->get('client_id', '')) . '">'
        . '<input type="hidden" name="redirect_uri" value="' . Html::escape($redirect_uri) . '">'
        . '<input type="hidden" name="state" value="' . Html::escape($state) . '">'
        . '<input type="hidden" name="scope" value="' . Html::escape((string) $request->query->get('scope', 'read')) . '">'
        . '<input type="hidden" name="response_type" value="code">'
        . '<br><button type="submit">Continue OAuth2 Flow</button>'
        . '</form>'
        . '<script>'
        . '(function(){'
        . 'const profiles=' . $profiles_json . ';'
        . 'const sel=document.getElementById("mock_user_uid");'
        . 'if(!sel){return;}'
        . 'sel.addEventListener("change", function(){'
        . 'const p=profiles[this.value];'
        . 'if(!p){return;}'
        . 'document.getElementById("mock_username").value=p.username || "";'
        . 'document.getElementById("mock_first_name").value=p.first_name || "";'
        . 'document.getElementById("mock_last_name").value=p.last_name || "";'
        . 'document.getElementById("mock_afm").value=p.afm || "";'
        . '});'
        . '})();'
        . '</script>';

      return [
        '#type' => 'inline_template',
        '#template' => '{{ content|raw }}',
        '#context' => ['content' => $form_html],
      ];
    }

    if ((string) $request->query->get('scenario', '') === 'deny') {
      $query = ['error' => 'access_denied', 'error_description' => 'Mock deny'];
      if ($state !== '') {
        $query['state'] = $state;
      }
      return new TrustedRedirectResponse($redirect_uri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    $payload = [
      'username' => (string) ($request->query->get('mock_username') ?: 'mock.gsis.user'),
      'first_name' => (string) ($request->query->get('mock_first_name') ?: 'Mock'),
      'last_name' => (string) ($request->query->get('mock_last_name') ?: 'User'),
      'afm' => (string) ($request->query->get('mock_afm') ?: '999999999'),
      'created' => \Drupal::time()->getRequestTime(),
    ];

    $code = bin2hex(random_bytes(10));
    $codes = $this->state->get(self::MOCK_CODES_STATE_KEY, []);
    if (!is_array($codes)) {
      $codes = [];
    }
    $codes[$code] = $payload;
    $this->state->set(self::MOCK_CODES_STATE_KEY, $codes);

    $query = ['code' => $code];
    if ($state !== '') {
      $query['state'] = $state;
    }

    return new TrustedRedirectResponse($redirect_uri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
  }

  /**
   * Build a list of active local users for mock impersonation.
   *
   * @return array<string, array{username: string, first_name: string, last_name: string, afm: string}>
   *   The user profiles keyed by uid.
   */
  private function buildMockUserProfiles(): array {
    $profiles = [];
    $storage = $this->entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('uid', 'ASC')
      ->range(0, 250)
      ->execute();

    foreach ($ids as $uid) {
      $account = $storage->load((int) $uid);
      if (!$account instanceof User) {
        continue;
      }

      $first_name = $account->hasField('field_first_name') ? trim((string) $account->get('field_first_name')->value) : '';
      $last_name = $account->hasField('field_last_name') ? trim((string) $account->get('field_last_name')->value) : '';
      $afm = $this->extractAfmFromUser($account);

      $profiles[(string) $uid] = [
        'username' => (string) $account->getAccountName(),
        'first_name' => $first_name !== '' ? $first_name : 'Unknown',
        'last_name' => $last_name !== '' ? $last_name : 'Unknown',
        'afm' => $afm,
      ];
    }

    return $profiles;
  }

  /**
   * Attempt to resolve AFM from user fields.
   */
  private function extractAfmFromUser(User $account): string {
    if ($account->hasField('field_gsis_info')) {
      $raw = trim((string) $account->get('field_gsis_info')->value);
      if ($raw !== '') {
        $decoded = json_decode($raw, TRUE);
        if (is_array($decoded)) {
          $afm = $this->extractAfmFromArray($decoded);
          if ($afm !== NULL) {
            return $afm;
          }
        }
      }
    }

    return '999999999';
  }

  public function mockToken(Request $request): JsonResponse {
    $code = (string) $request->request->get('code', '');
    $grant_type = (string) $request->request->get('grant_type', '');

    if ($grant_type !== 'authorization_code' || $code === '') {
      return new JsonResponse(['error' => 'invalid_request'], 400);
    }

    $codes = $this->state->get(self::MOCK_CODES_STATE_KEY, []);
    if (!is_array($codes) || !isset($codes[$code])) {
      return new JsonResponse(['error' => 'invalid_grant'], 400);
    }

    $payload = $codes[$code];
    unset($codes[$code]);
    $this->state->set(self::MOCK_CODES_STATE_KEY, $codes);

    $token = 'mocktok_' . bin2hex(random_bytes(12));
    $tokens = $this->state->get(self::MOCK_TOKENS_STATE_KEY, []);
    if (!is_array($tokens)) {
      $tokens = [];
    }
    $tokens[$token] = $payload;
    $this->state->set(self::MOCK_TOKENS_STATE_KEY, $tokens);

    return new JsonResponse([
      'access_token' => $token,
      'token_type' => 'Bearer',
      'expires_in' => 3600,
      'scope' => 'read',
    ]);
  }

  public function mockUserinfo(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\Response {
    $header = (string) $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\\s+(.+)$/i', $header, $matches)) {
      return new JsonResponse(['error' => 'unauthorized'], 401);
    }

    $token = trim((string) $matches[1]);
    $tokens = $this->state->get(self::MOCK_TOKENS_STATE_KEY, []);
    if (!is_array($tokens) || !isset($tokens[$token])) {
      return new JsonResponse(['error' => 'invalid_token'], 401);
    }

    $user = $tokens[$token];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<userinfo>'
      . '<USERNAME>' . htmlspecialchars((string) ($user['username'] ?? ''), ENT_XML1) . '</USERNAME>'
      . '<FIRSTNAME>' . htmlspecialchars((string) ($user['first_name'] ?? ''), ENT_XML1) . '</FIRSTNAME>'
      . '<LASTNAME>' . htmlspecialchars((string) ($user['last_name'] ?? ''), ENT_XML1) . '</LASTNAME>'
      . '<AFM>' . htmlspecialchars((string) ($user['afm'] ?? ''), ENT_XML1) . '</AFM>'
      . '</userinfo>';

    return new \Symfony\Component\HttpFoundation\Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
  }

  private function extractXmlValue(\SimpleXMLElement $xml, array $candidateNames): ?string {
    foreach ($candidateNames as $name) {
      $needle = strtoupper(trim((string) $name));
      if ($needle === '') {
        continue;
      }

      $query = sprintf('//*[translate(local-name(), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="%s"]', $needle);
      $matches = $xml->xpath($query);
      if (!is_array($matches) || !isset($matches[0])) {
        continue;
      }

      $value = trim((string) $matches[0]);
      if ($value !== '') {
        return $value;
      }
    }

    return NULL;
  }

  /**
   * Resolve the best user match and the reason when no match is found.
   *
   * @return array{user: ?\Drupal\user\Entity\User, reason: string}
   *   reason values:
   *   - matched
   *   - afm_mismatch
   *   - no_match
   */
  private function resolveUserMatch(string $username, ?string $firstName, ?string $lastName, ?string $afm): array {
    $storage = $this->entityTypeManager()->getStorage('user');

    $afm_decision = $this->matchByGsisInfoAfm($afm);
    if ($afm_decision['status'] === 'matched') {
      return ['user' => $afm_decision['user'], 'reason' => 'matched'];
    }
    if ($afm_decision['status'] === 'mismatch') {
      return ['user' => NULL, 'reason' => 'afm_mismatch'];
    }

    $accounts = $storage->loadByProperties(['name' => $username]);
    if (!empty($accounts)) {
      $candidate = reset($accounts);
      return ['user' => ($candidate instanceof User ? $candidate : NULL), 'reason' => 'matched'];
    }

    $from_gsis_info = $this->matchByGsisInfo($username, $firstName, $lastName);
    if ($from_gsis_info instanceof User) {
      return ['user' => $from_gsis_info, 'reason' => 'matched'];
    }

    if ($firstName !== NULL && $lastName !== NULL) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('field_first_name', $firstName)
        ->condition('field_last_name', $lastName)
        ->range(0, 2);
      $ids = $query->execute();
      if (count($ids) === 1) {
        $id = (int) reset($ids);
        $account = $storage->load($id);
        return ['user' => ($account instanceof User ? $account : NULL), 'reason' => 'matched'];
      }
    }

    return ['user' => NULL, 'reason' => 'no_match'];
  }

  /**
   * Decide match based on field_gsis_info.afm as a hard gate.
   *
   * @return array{status: string, user: ?\Drupal\user\Entity\User}
   *   status:
   *   - matched: AFM matched exactly and user is returned.
   *   - mismatch: AFM data exists in field_gsis_info, but none matched incoming.
   *   - no_afm_data: no usable AFM data found, caller may fallback to other rules.
   */
  private function matchByGsisInfoAfm(?string $afm): array {
    $incoming_afm = trim((string) $afm);
    if ($incoming_afm === '') {
      return ['status' => 'no_afm_data', 'user' => NULL];
    }

    $storage = $this->entityTypeManager()->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->exists('field_gsis_info')
      ->range(0, 250);
    $ids = $query->execute();

    $has_stored_afm = FALSE;
    $matched = [];

    foreach ($ids as $id) {
      $account = $storage->load((int) $id);
      if (!$account instanceof User || !$account->hasField('field_gsis_info')) {
        continue;
      }

      $raw = trim((string) $account->get('field_gsis_info')->value);
      if ($raw === '') {
        continue;
      }

      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        continue;
      }

      $stored_afm = $this->extractAfmFromArray($decoded);
      if ($stored_afm === NULL) {
        continue;
      }

      $has_stored_afm = TRUE;
      if ($stored_afm === $incoming_afm) {
        $matched[] = $account;
      }
    }

    if (!empty($matched)) {
      return ['status' => 'matched', 'user' => reset($matched)];
    }

    if ($has_stored_afm) {
      return ['status' => 'mismatch', 'user' => NULL];
    }

    return ['status' => 'no_afm_data', 'user' => NULL];
  }

  private function matchByGsisInfo(string $username, ?string $firstName, ?string $lastName): ?User {
    $storage = $this->entityTypeManager()->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->exists('field_gsis_info')
      ->range(0, 250);
    $ids = $query->execute();

    foreach ($ids as $id) {
      $account = $storage->load((int) $id);
      if (!$account instanceof User || !$account->hasField('field_gsis_info')) {
        continue;
      }
      $raw = trim((string) $account->get('field_gsis_info')->value);
      if ($raw === '') {
        continue;
      }
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        continue;
      }

      $stored_username = isset($decoded['username']) ? trim((string) $decoded['username']) : '';
      if ($stored_username !== '' && $stored_username === $username) {
        return $account;
      }

      $stored_first = isset($decoded['first_name']) ? trim((string) $decoded['first_name']) : '';
      $stored_last = isset($decoded['last_name']) ? trim((string) $decoded['last_name']) : '';
      if ($firstName !== NULL && $lastName !== NULL && $stored_first === $firstName && $stored_last === $lastName) {
        return $account;
      }
    }

    return NULL;
  }

  /**
   * @param array<string, mixed> $decoded
   */
  private function extractAfmFromArray(array $decoded): ?string {
    $candidates = [
      $decoded['afm'] ?? NULL,
      $decoded['AFM'] ?? NULL,
      $decoded['taxid'] ?? NULL,
      $decoded['TaxId'] ?? NULL,
    ];

    foreach ($candidates as $candidate) {
      if (is_string($candidate) && trim($candidate) !== '') {
        return trim($candidate);
      }
    }

    return NULL;
  }

  /**
   * @param array<string, string|null> $details
   */
  private function createUserFromDetails(array $details): User {
    $storage = $this->entityTypeManager()->getStorage('user');

    $base = strtolower((string) ($details['username'] ?? 'gsis_user'));
    $base = preg_replace('/[^a-z0-9_.-]+/', '_', $base) ?: 'gsis_user';
    $candidate = $base;
    $counter = 1;
    while (!empty($storage->loadByProperties(['name' => $candidate]))) {
      $counter++;
      $candidate = $base . '_' . $counter;
    }

    $account = User::create([
      'name' => $candidate,
      'mail' => $candidate . '@gsis-pa.invalid',
      'status' => 1,
    ]);
    $account->setPassword(bin2hex(random_bytes(16)));
    return $account;
  }

  /**
   * @param array<string, string|null> $details
   */
  private function syncUserFields(User $user, array $details): void {
    if (
      $user->hasField('field_first_name') &&
      !empty($details['first_name']) &&
      trim((string) $user->get('field_first_name')->value) === ''
    ) {
      $user->set('field_first_name', $details['first_name']);
    }
    if (
      $user->hasField('field_last_name') &&
      !empty($details['last_name']) &&
      trim((string) $user->get('field_last_name')->value) === ''
    ) {
      $user->set('field_last_name', $details['last_name']);
    }
    if (
      $user->hasField('field_gsis_info')
    ) {
      $payload = $details;
      $payload['updated_at'] = gmdate('c');
      $user->set('field_gsis_info', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
  }

  /**
   * @return array{enabled: bool, allow_user_creation: bool, default_roles: array<int, string>}
   */
  private function getGlueSettings(): array {
    $defaults = [
      'enabled' => TRUE,
      'allow_user_creation' => FALSE,
      'default_roles' => [],
    ];

    $settings = Settings::get('kemke_users_gsis_pa_auth2', []);
    if (!is_array($settings)) {
      return $defaults;
    }

    $merged = $settings + $defaults;
    if (!is_array($merged['default_roles'])) {
      $merged['default_roles'] = [];
    }

    return $merged;
  }

}
