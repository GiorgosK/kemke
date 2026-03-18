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
use Drupal\kemke_gsis_pa_oauth2_client\Logger\GsisPaCallLogger;
use Drupal\oauth2_client\Entity\Oauth2Client;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Plugin\Oauth2GrantType\AuthorizationCode;
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
    private readonly GsisPaCallLogger $callLogger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('oauth2_client.service'),
      $container->get('http_client'),
      $container->get('request_stack'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('state'),
      $container->get('kemke_gsis_pa_oauth2_client.call_logger'),
    );
  }

  public function login(): RedirectResponse {
    $settings = $this->getGlueSettings();
    if (!$settings['enabled']) {
      $this->messengerService->addError($this->t('GSIS login is currently disabled.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && ($request->query->has('code') || $request->query->has('error'))) {
      return $this->handleAuthorizationCallback($request);
    }

    if ($this->currentAccount->isAuthenticated()) {
      if (function_exists('user_pending_role_notice_get_redirect_url')) {
        $account = $this->entityTypeManager()->getStorage('user')->load($this->currentAccount->id());
        if ($account instanceof User) {
          $pending_redirect = user_pending_role_notice_get_redirect_url($account);
          if ($pending_redirect instanceof Url) {
            return new RedirectResponse($pending_redirect->toString());
          }
        }
      }
      return new RedirectResponse('/incoming');
    }

    if ($request !== NULL) {
      $destination = (string) $request->query->get('destination', '');
      if ($destination !== '' && $destination[0] === '/') {
        $request->getSession()->set(self::SESSION_DESTINATION_KEY, $destination);
      }
    }

    // Force a fresh auth flow for deterministic testing.
    $this->oauth2ClientService->clearAccessToken(self::OAUTH_PLUGIN_ID);
    $client = $this->oauth2ClientService->getClient(self::OAUTH_PLUGIN_ID);
    $this->callLogger->log('login_start', [
      'client_id' => $client->getClientId(),
      'authorization_uri' => $client->getAuthorizationUri(),
      'redirect_uri' => $client->getRedirectUri(),
      'scopes' => $client->getScopes(),
      'is_authenticated' => $this->currentAccount->isAuthenticated(),
    ]);

    // For authorization_code this triggers a redirect by throwing
    // AuthCodeRedirect, handled by Drupal's kernel.
    $token = $this->oauth2ClientService->getAccessToken(self::OAUTH_PLUGIN_ID, NULL);
    if ($token !== NULL) {
      return new RedirectResponse(Url::fromRoute('kemke_users_gsis_pa_auth2.finalize')->toString());
    }

    $this->messengerService->addError($this->t('Unable to start GSIS OAuth2 flow. Check OAuth2 client credentials and plugin status.'));
    return new RedirectResponse(Url::fromRoute('user.login')->toString());
  }

  private function handleAuthorizationCallback(Request $request): RedirectResponse {
    $error = trim((string) $request->query->get('error', ''));
    if ($error !== '') {
      $this->callLogger->log('authorization_callback_error', [
        'error' => $error,
        'error_description' => trim((string) $request->query->get('error_description', '')),
      ]);
      $this->messengerService->addError($this->t('GSIS login failed: @error.', ['@error' => $error]));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $code = trim((string) $request->query->get('code', ''));
    $state = trim((string) $request->query->get('state', ''));
    if ($code === '' || $state === '') {
      $this->messengerService->addError($this->t('GSIS login failed: missing authorization callback parameters.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $oauth2Client = $this->entityTypeManager()->getStorage('oauth2_client')->load(self::OAUTH_PLUGIN_ID);
    if (!$oauth2Client instanceof Oauth2Client) {
      $this->messengerService->addError($this->t('GSIS login failed: OAuth2 client configuration is missing.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $clientPlugin = $oauth2Client->getClient();
    if (!$clientPlugin instanceof Oauth2ClientPluginInterface) {
      $this->messengerService->addError($this->t('GSIS login failed: OAuth2 client plugin is unavailable.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $tempstore = \Drupal::service('tempstore.private')->get('oauth2_client');
    $storedState = (string) $tempstore->get('oauth2_client_state-' . self::OAUTH_PLUGIN_ID);
    if ($state !== $storedState) {
      $tempstore->delete('oauth2_client_state-' . self::OAUTH_PLUGIN_ID);
      $this->messengerService->addError($this->t('GSIS login failed: invalid OAuth state.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $grantPlugin = \Drupal::service('plugin.manager.oauth2_grant_type')->createInstance('authorization_code');
    if (!$grantPlugin instanceof AuthorizationCode) {
      $this->messengerService->addError($this->t('GSIS login failed: authorization code grant is unavailable.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    if (!$grantPlugin->requestAccessToken($clientPlugin, $code)) {
      $this->callLogger->log('authorization_callback_token_exchange_failed', [
        'redirect_uri' => $clientPlugin->getRedirectUri(),
      ]);
      $this->messengerService->addError($this->t('GSIS login failed while exchanging the authorization code.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    return $grantPlugin->getPostCaptureRedirect($clientPlugin);
  }

  public function finalize(): RedirectResponse {
    $token = $this->oauth2ClientService->retrieveAccessToken(self::OAUTH_PLUGIN_ID);
    if ($token === NULL) {
      $this->messengerService->addError($this->t('GSIS login failed: missing access token.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $client = $this->oauth2ClientService->getClient(self::OAUTH_PLUGIN_ID);
    $userinfo_url = $client->getResourceUri();
    $this->callLogger->log('userinfo_request_start', [
      'url' => $userinfo_url,
    ]);

    try {
      $response = $this->httpClient->request('GET', $userinfo_url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token->getToken(),
          'Accept' => 'application/xml, text/xml, */*',
        ],
        'timeout' => 20,
      ]);
      $this->callLogger->log('userinfo_request_success', [
        'url' => $userinfo_url,
        'status_code' => $response->getStatusCode(),
      ]);
    }
    catch (\Throwable $throwable) {
      $this->callLogger->log('userinfo_request_error', [
        'url' => $userinfo_url,
        'error' => $throwable->getMessage(),
      ]);
      $this->getLogger('kemke_users_gsis_pa_auth2')->error('Userinfo request failed: @message', ['@message' => $throwable->getMessage()]);
      $this->messengerService->addError($this->t('GSIS login failed while fetching user info.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $xml_string = trim((string) $response->getBody());
    $this->callLogger->log('userinfo_response_body', [
      'url' => $userinfo_url,
      'status_code' => $response->getStatusCode(),
      'raw_xml' => $this->sanitizeUserinfoXmlForLog($xml_string),
      'raw_xml_truncated' => strlen($xml_string) > 16000,
    ]);

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
      'userid' => $this->extractXmlValue($xml, ['USERID', 'userid', 'userId']),
      'first_name' => $this->extractXmlValue($xml, ['ONOMA', 'FIRSTNAME', 'firstname', 'name']),
      'last_name' => $this->extractXmlValue($xml, ['EPONYMO', 'LASTNAME', 'lastname', 'surname']),
      'afm' => $this->extractXmlValue($xml, ['AFM', 'taxid', 'TIN']),
    ];
    $raw_payload = $this->extractReceivedPayload($xml);

    if ($details['afm'] === NULL && $details['userid'] === NULL) {
      $this->callLogger->log('finalize_missing_afm', [
        'gsis_user' => $this->buildGsisUserContext($details),
      ]);
      $this->messengerService->addError($this->t('GSIS login failed: AFM and userid are both missing from response.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $match_result = $this->resolveUserMatch($details['afm'], $details['userid']);
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
      if (in_array($match_result['reason'], ['duplicate_afm', 'duplicate_userid', 'conflicting_identifiers'], TRUE)) {
        $this->callLogger->log('match_failed_duplicate_afm', [
          'gsis_user' => $this->buildGsisUserContext($details),
        ]);
        $this->messengerService->addError($this->t('Login denied: GSIS AFM/userid matches multiple or conflicting local accounts.'));
        return new RedirectResponse(Url::fromRoute('user.login')->toString());
      }
      $this->callLogger->log('match_failed_no_local_user', [
        'gsis_user' => $this->buildGsisUserContext($details),
      ]);
      $this->messengerService->addError($this->t('No matching local user account was found for GSIS AFM/userid.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    if (!$user->isActive()) {
      $this->callLogger->log('match_blocked_user', [
        'gsis_user' => $this->buildGsisUserContext($details),
      ]);
      $this->messengerService->addError($this->t('Your local account is blocked. Contact an administrator.'));
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }

    $this->syncUserFields($user, $details, $raw_payload);
    $user->save();

    user_login_finalize($user);
    $this->callLogger->log('login_success', [
      'gsis_user' => $this->buildGsisUserContext($details),
    ]);

    $request = $this->requestStack->getCurrentRequest();
    $destination = '/incoming';
    $has_pending_redirect = FALSE;
    if (function_exists('user_pending_role_notice_get_redirect_url')) {
      $pending_redirect = user_pending_role_notice_get_redirect_url($user);
      if ($pending_redirect instanceof Url) {
        $destination = $pending_redirect->toString();
        $has_pending_redirect = TRUE;
      }
    }
    if ($request !== NULL) {
      $stored = (string) $request->getSession()->get(self::SESSION_DESTINATION_KEY, '');
      if ($stored !== '' && $stored[0] === '/' && !$has_pending_redirect) {
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

    $has_mock_inputs = $request->query->has('mock_payload')
      || $request->query->has('scenario');

    if (!$has_mock_inputs) {
      $profiles = $this->buildMockUserProfiles();
      $selected_uid = (string) $request->query->get('mock_user_uid', (string) array_key_first($profiles));
      $selected_profile = ($selected_uid !== '' && isset($profiles[$selected_uid])) ? $profiles[$selected_uid] : NULL;

      $default_payload = $selected_profile['payload'] ?? [
        'userid' => 'mock.gsis.user',
        'taxid' => '999999999',
        'lastname' => 'User',
        'firstname' => 'Mock',
        'fathername' => 'null',
        'mothername' => 'null',
        'birthyear' => '1984',
      ];
      $default_payload_json = json_encode($default_payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
      if (!is_string($default_payload_json)) {
        $default_payload_json = '{}';
      }

      $options = '';
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

      $button_css = 'display:inline-block;padding:12px 18px;border:0;border-radius:6px;background:#114b5f;color:#fff;font:600 14px/1.2 sans-serif;cursor:pointer;';
      $form_html = '<form method="get" style="max-width:760px;padding:20px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc">'
        . '<h2>Mock GSIS OAuth2 Login</h2>'
        . '<p>Select an existing Drupal user, review the raw payload, then modify the JSON before continuing if needed.</p>'
        . '<label>Drupal user<br><select id="mock_user_uid" name="mock_user_uid" style="width:100%">' . $options . '</select></label><br><br>'
        . '<label>Payload<br><textarea id="mock_payload" name="mock_payload" rows="12" style="width:100%;font:13px/1.5 monospace;border:1px solid #94a3b8;border-radius:8px;padding:12px;background:#fff">'
        . Html::escape($default_payload_json)
        . '</textarea></label><br><br>'
        . '<label>Scenario<br><select name="scenario" style="width:100%">'
        . '<option value="">Success</option>'
        . '<option value="deny">Deny access</option>'
        . '</select></label>'
        . '<input type="hidden" name="client_id" value="' . Html::escape((string) $request->query->get('client_id', '')) . '">'
        . '<input type="hidden" name="redirect_uri" value="' . Html::escape($redirect_uri) . '">'
        . '<input type="hidden" name="state" value="' . Html::escape($state) . '">'
        . '<input type="hidden" name="scope" value="' . Html::escape((string) $request->query->get('scope', 'read')) . '">'
        . '<input type="hidden" name="response_type" value="code">'
        . '<br><button type="submit" style="' . $button_css . '">Submit Mock Login</button>'
        . '</form>'
        . '<script>'
        . '(function(){'
        . 'const profiles=' . $profiles_json . ';'
        . 'const sel=document.getElementById("mock_user_uid");'
        . 'const payload=document.getElementById("mock_payload");'
        . 'if(!sel){return;}'
        . 'sel.addEventListener("change", function(){'
        . 'const p=profiles[this.value];'
        . 'if(!p||!payload){return;}'
        . 'payload.value=JSON.stringify(p.payload || {}, null, 4);'
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

    $payload = json_decode((string) $request->query->get('mock_payload', '{}'), TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }
    $payload['created'] = (string) \Drupal::time()->getRequestTime();

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
   * @return array<string, array{username: string, userid: string, first_name: string, last_name: string, afm: string, payload: array<string, string>}>
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
      $userid = $this->extractUseridFromUser($account);
      if ($afm === '') {
        $afm = $this->generateMockAfm((int) $uid);
      }
      if ($userid === '') {
        $userid = (string) $account->getAccountName();
      }

      $profiles[(string) $uid] = [
        'username' => (string) $account->getAccountName(),
        'userid' => $userid,
        'first_name' => $first_name !== '' ? $first_name : 'Unknown',
        'last_name' => $last_name !== '' ? $last_name : 'Unknown',
        'afm' => $afm,
        'payload' => [
          'userid' => $userid,
          'taxid' => $afm,
          'lastname' => $last_name !== '' ? $last_name : 'Unknown',
          'firstname' => $first_name !== '' ? $first_name : 'Unknown',
          'fathername' => 'null',
          'mothername' => 'null',
          'birthyear' => '1984',
        ],
      ];
    }

    return $profiles;
  }

  /**
   * Attempt to resolve AFM from user fields.
   */
  private function extractAfmFromUser(User $account): string {
    if ($account->hasField('field_gsis_afm')) {
      $afm = trim((string) $account->get('field_gsis_afm')->value);
      if ($afm !== '') {
        return $afm;
      }
    }

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

    return '';
  }

  /**
   * Attempt to resolve userid from user fields.
   */
  private function extractUseridFromUser(User $account): string {
    if ($account->hasField('field_gsis_userid')) {
      $userid = trim((string) $account->get('field_gsis_userid')->value);
      if ($userid !== '') {
        return $userid;
      }
    }

    if ($account->hasField('field_gsis_info')) {
      $raw = trim((string) $account->get('field_gsis_info')->value);
      if ($raw !== '') {
        $decoded = json_decode($raw, TRUE);
        if (is_array($decoded)) {
          $userid = $this->extractUseridFromArray($decoded);
          if ($userid !== NULL) {
            return $userid;
          }
        }
      }
    }

    return '';
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
    $xml = '<?xml version="1.0" encoding="UTF-8"?><userinfo>';
    foreach ($user as $key => $value) {
      $name = preg_replace('/[^A-Za-z0-9_.:-]+/', '', (string) $key);
      if (!is_string($name) || $name === '' || preg_match('/^[0-9.-]/', $name)) {
        continue;
      }
      if (is_scalar($value) || $value === NULL) {
        $xml .= '<' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $name . '>';
      }
    }
    $xml .= '</userinfo>';

    return new \Symfony\Component\HttpFoundation\Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
  }

  private function extractXmlValue(\SimpleXMLElement $xml, array $candidateNames): ?string {
    foreach ($candidateNames as $name) {
      $needle = strtoupper(trim((string) $name));
      if ($needle === '') {
        continue;
      }

      // GSIS may return userinfo values either as child elements or as
      // attributes on the <userinfo /> node. Support both shapes.
      $attribute_matches = $xml->xpath(sprintf('//@*[translate(local-name(), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="%s"]', $needle));
      if (is_array($attribute_matches) && isset($attribute_matches[0])) {
        $value = trim((string) $attribute_matches[0]);
        if ($value !== '') {
          return $value;
        }
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
   *   - duplicate_afm
   *   - duplicate_userid
   *   - conflicting_identifiers
   *   - no_match
   */
  private function resolveUserMatch(?string $afm, ?string $userid): array {
    $incoming_afm = trim((string) $afm);
    $incoming_userid = trim((string) $userid);
    if ($incoming_afm === '' && $incoming_userid === '') {
      return ['user' => NULL, 'reason' => 'no_match'];
    }

    $afm_match = $incoming_afm !== '' ? $this->matchByDedicatedAfm($incoming_afm) : ['user' => NULL, 'reason' => 'no_match'];
    if ($afm_match['reason'] === 'duplicate_afm') {
      return $afm_match;
    }

    $userid_match = $incoming_userid !== '' ? $this->matchByDedicatedUserid($incoming_userid) : ['user' => NULL, 'reason' => 'no_match'];
    if ($userid_match['reason'] === 'duplicate_userid') {
      return $userid_match;
    }

    $afm_user = $afm_match['user'] instanceof User ? $afm_match['user'] : ($incoming_afm !== '' ? $this->matchByGsisInfoAfm($incoming_afm) : NULL);
    $userid_user = $userid_match['user'] instanceof User ? $userid_match['user'] : ($incoming_userid !== '' ? $this->matchByGsisInfoUserid($incoming_userid) : NULL);

    if ($afm_user instanceof User && $userid_user instanceof User && (int) $afm_user->id() !== (int) $userid_user->id()) {
      return ['user' => NULL, 'reason' => 'conflicting_identifiers'];
    }

    if ($afm_user instanceof User) {
      return ['user' => $afm_user, 'reason' => 'matched'];
    }

    if ($userid_user instanceof User) {
      return ['user' => $userid_user, 'reason' => 'matched'];
    }

    return ['user' => NULL, 'reason' => 'no_match'];
  }

  /**
   * Match exactly on the dedicated AFM field.
   *
   * @return array{user: ?\Drupal\user\Entity\User, reason: string}
   */
  private function matchByDedicatedAfm(string $afm): array {
    $storage = $this->entityTypeManager()->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('field_gsis_afm', $afm)
      ->range(0, 2);
    $ids = array_values($query->execute());

    if (count($ids) > 1) {
      return ['user' => NULL, 'reason' => 'duplicate_afm'];
    }

    if (count($ids) === 1) {
      $account = $storage->load((int) $ids[0]);
      return ['user' => ($account instanceof User ? $account : NULL), 'reason' => 'matched'];
    }

    return ['user' => NULL, 'reason' => 'no_match'];
  }

  /**
   * Match exactly on the dedicated userid field.
   *
   * @return array{user: ?\Drupal\user\Entity\User, reason: string}
   */
  private function matchByDedicatedUserid(string $userid): array {
    $storage = $this->entityTypeManager()->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('field_gsis_userid', $userid)
      ->range(0, 2);
    $ids = array_values($query->execute());

    if (count($ids) > 1) {
      return ['user' => NULL, 'reason' => 'duplicate_userid'];
    }

    if (count($ids) === 1) {
      $account = $storage->load((int) $ids[0]);
      return ['user' => ($account instanceof User ? $account : NULL), 'reason' => 'matched'];
    }

    return ['user' => NULL, 'reason' => 'no_match'];
  }

  /**
   * Decide match based on legacy field_gsis_info.afm as a hard gate.
   *
   * @return \Drupal\user\Entity\User|null
   *   A user matched exactly by stored AFM, or NULL if there is no exact AFM hit.
   */
  private function matchByGsisInfoAfm(string $incoming_afm): ?User {
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

      $stored_afm = $this->extractAfmFromArray($decoded);
      if ($stored_afm === NULL) {
        continue;
      }

      if ($stored_afm === $incoming_afm) {
        return $account;
      }
    }

    return NULL;
  }

  /**
   * Decide match based on legacy field_gsis_info.userid as a hard gate.
   *
   * @return \Drupal\user\Entity\User|null
   *   A user matched exactly by stored userid, or NULL if there is no exact userid hit.
   */
  private function matchByGsisInfoUserid(string $incoming_userid): ?User {
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

      $stored_userid = $this->extractUseridFromArray($decoded);
      if ($stored_userid === NULL) {
        continue;
      }

      if ($stored_userid === $incoming_userid) {
        return $account;
      }
    }

    return NULL;
  }

  /**
   * @param array<string, mixed> $decoded
   */
  private function extractAfmFromArray(array $decoded): ?string {
    return $this->extractValueFromArray($decoded, ['afm', 'AFM', 'taxid', 'TaxId', 'TIN', 'tin']);
  }

  /**
   * @param array<string, mixed> $decoded
   */
  private function extractUseridFromArray(array $decoded): ?string {
    return $this->extractValueFromArray($decoded, ['userid', 'USERID', 'userId']);
  }

  /**
   * @param array<string, mixed> $decoded
   * @param array<int, string> $candidateKeys
   */
  private function extractValueFromArray(array $decoded, array $candidateKeys): ?string {
    foreach ($candidateKeys as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate === '') {
        continue;
      }

      foreach ($decoded as $key => $value) {
        if (strcasecmp((string) $key, $candidate) !== 0) {
          continue;
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
          return trim((string) $value);
        }
      }
    }

    return NULL;
  }

  private function generateMockAfm(int $uid): string {
    return '9' . str_pad((string) $uid, 8, '0', STR_PAD_LEFT);
  }

  /**
   * @param array<string, string|null> $details
   */
  private function createUserFromDetails(array $details): User {
    $storage = $this->entityTypeManager()->getStorage('user');

    $base_source = $details['username'] ?? NULL;
    if (!is_string($base_source) || trim($base_source) === '') {
      $afm_suffix = preg_replace('/[^0-9]+/', '', (string) ($details['afm'] ?? ''));
      $base_source = $afm_suffix !== '' ? 'gsis_' . $afm_suffix : 'gsis_user';
    }

    $base = strtolower((string) $base_source);
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
   * @param array<string, string> $rawPayload
   */
  private function syncUserFields(User $user, array $details, array $rawPayload): void {
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
      $user->hasField('field_gsis_afm') &&
      !empty($details['afm'])
    ) {
      $user->set('field_gsis_afm', $details['afm']);
    }
    if (
      $user->hasField('field_gsis_userid') &&
      !empty($details['userid'])
    ) {
      $user->set('field_gsis_userid', $details['userid']);
    }
    if (
      $user->hasField('field_gsis_info')
    ) {
      $payload = !empty($rawPayload) ? $rawPayload : $this->buildLegacyPayload($details);
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

  /**
   * @param array<string, string|null> $details
   *
   * @return array<string, string>
   */
  private function buildGsisUserContext(array $details): array {
    return [
      'username' => trim((string) ($details['username'] ?? '')),
      'userid' => trim((string) ($details['userid'] ?? '')),
      'first_name' => trim((string) ($details['first_name'] ?? '')),
      'last_name' => trim((string) ($details['last_name'] ?? '')),
      'afm' => trim((string) ($details['afm'] ?? '')),
    ];
  }

  private function sanitizeUserinfoXmlForLog(string $xml): string {
    $normalized = preg_replace('/[^\P{C}\t\r\n]/u', '', $xml);
    if (!is_string($normalized)) {
      $normalized = $xml;
    }

    $normalized = trim($normalized);
    if (strlen($normalized) > 16000) {
      return substr($normalized, 0, 16000);
    }

    return $normalized;
  }

  /**
   * @return array<string, string>
   */
  private function extractReceivedPayload(\SimpleXMLElement $xml): array {
    $nodes = $xml->xpath('//*[count(@*) > 0 or count(*) > 0]');
    if (!is_array($nodes)) {
      return [];
    }

    foreach ($nodes as $node) {
      if (!$node instanceof \SimpleXMLElement) {
        continue;
      }

      $payload = $this->flattenXmlNode($node);
      if ($payload !== []) {
        return $payload;
      }
    }

    return [];
  }

  /**
   * @return array<string, string>
   */
  private function flattenXmlNode(\SimpleXMLElement $node): array {
    $payload = [];

    foreach ($node->attributes() as $name => $value) {
      $payload[(string) $name] = trim((string) $value);
    }

    foreach ($node->children() as $child) {
      $child_name = (string) $child->getName();
      $child_value = trim((string) $child);
      if ($child_name !== '' && $child_value !== '') {
        $payload[$child_name] = $child_value;
      }
    }

    return $payload;
  }

  /**
   * @param array<string, string|null> $details
   *
   * @return array<string, string>
   */
  private function buildLegacyPayload(array $details): array {
    $payload = [];
    if (!empty($details['username'])) {
      $payload['username'] = trim((string) $details['username']);
    }
    if (!empty($details['userid'])) {
      $payload['userid'] = trim((string) $details['userid']);
    }
    if (!empty($details['first_name'])) {
      $payload['first_name'] = trim((string) $details['first_name']);
    }
    if (!empty($details['last_name'])) {
      $payload['last_name'] = trim((string) $details['last_name']);
    }
    if (!empty($details['afm'])) {
      $payload['afm'] = trim((string) $details['afm']);
    }

    return $payload;
  }

}
