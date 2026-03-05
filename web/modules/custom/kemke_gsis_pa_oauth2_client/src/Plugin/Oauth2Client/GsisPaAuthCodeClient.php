<?php

declare(strict_types=1);

namespace Drupal\kemke_gsis_pa_oauth2_client\Plugin\Oauth2Client;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\Url;
use Drupal\oauth2_client\Attribute\Oauth2Client;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginAccessInterface;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginBase;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginRedirectInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * GSIS PA OAuth2 client plugin.
 */
#[Oauth2Client(
  id: 'kemke_gsis_pa',
  name: new \Drupal\Core\StringTranslation\TranslatableMarkup('KEMKE GSIS PA OAuth2'),
  grant_type: 'authorization_code',
  authorization_uri: 'https://test.gsis.gr/oauth2servergov/oauth/authorize',
  token_uri: 'https://test.gsis.gr/oauth2servergov/oauth/token',
  resource_owner_uri: 'https://test.gsis.gr/oauth2servergov/userinfo?format=xml',
  scopes: ['read'],
  scope_separator: ' ',
)]
final class GsisPaAuthCodeClient extends Oauth2ClientPluginBase implements Oauth2ClientPluginAccessInterface, Oauth2ClientPluginRedirectInterface {

  private PrivateTempStore $tempStore;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): Oauth2ClientPluginInterface {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->tempStore = $container->get('tempstore.private')->get('kemke_users_gsis_pa_auth2');
    return $instance;
  }

  public function codeRouteAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowed();
  }

  public function getPostCaptureRedirect(): RedirectResponse {
    $url = Url::fromRoute('kemke_users_gsis_pa_auth2.finalize');
    return new RedirectResponse($url->toString(TRUE)->getGeneratedUrl());
  }

  public function storeAccessToken(AccessTokenInterface $accessToken): void {
    $this->tempStore->set('oauth2_client_access_token-' . $this->getId(), $accessToken);
  }

  public function retrieveAccessToken(): ?AccessTokenInterface {
    return $this->tempStore->get('oauth2_client_access_token-' . $this->getId());
  }

  public function clearAccessToken(): void {
    $this->tempStore->delete('oauth2_client_access_token-' . $this->getId());
  }

  public function getAuthorizationUri(): string {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && !empty($settings['authorization_uri'])) {
      return (string) $settings['authorization_uri'];
    }
    return parent::getAuthorizationUri();
  }

  public function getTokenUri(): string {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && !empty($settings['token_uri'])) {
      return (string) $settings['token_uri'];
    }
    return parent::getTokenUri();
  }

  public function getResourceUri(): string {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && !empty($settings['resource_owner_uri'])) {
      return (string) $settings['resource_owner_uri'];
    }
    return parent::getResourceUri();
  }

  public function getScopes(): ?array {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && !empty($settings['scopes']) && is_array($settings['scopes'])) {
      return $settings['scopes'];
    }
    return parent::getScopes();
  }

}
