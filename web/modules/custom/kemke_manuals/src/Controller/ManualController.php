<?php

declare(strict_types=1);

namespace Drupal\kemke_manuals\Controller;

use Drupal\Core\Link;
use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays role-specific manual resources.
 */
final class ManualController implements ContainerInjectionInterface {

  /**
   * Default manual resources, overridden by $settings['kemke_manuals_links_per_role'].
   */
  private const LINKS_PER_ROLE = [
    'kemke_admin' => [
      'Εγχειρίδιο' => '/manuals/admin.pdf',
      'Βίντεο Εκμάθησης' => 'https://www.youtube.com/',
    ],
    'amke_user' => [
      'Εγχειρίδιο' => '/manuals/amke.pdf',
      'Βίντεο Εκμάθησης' => 'https://www.youtube.com/',
    ],
    'default' => [
      'Εγχειρίδιο' => '/manuals/user.pdf',
      'Βίντεο Εκμάθησης' => 'https://www.youtube.com/',
    ],
  ];

  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly Settings $siteSettings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('settings'),
    );
  }

  /**
   * Displays role-specific manual resources as a list of links.
   */
  public function manualPage(): array {
    $links_per_role = $this->siteSettings->get('kemke_manuals_links_per_role');
    if (!is_array($links_per_role)) {
      $links_per_role = $this->siteSettings->get('kemke_manuals_paths', self::LINKS_PER_ROLE);
    }

    if (!is_array($links_per_role)) {
      throw new NotFoundHttpException('Manual resources are not configured.');
    }

    $links = $this->resolveRoleLinks($this->normalizeLinksPerRole($links_per_role));
    if ($links === []) {
      throw new NotFoundHttpException('Manual links are missing.');
    }

    $items = [];
    foreach ($links as $title => $target) {
      if (is_int($title)) {
        $section_title = is_string($target) ? trim($target) : '';
        if ($section_title === '') {
          continue;
        }

        $items[] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '<strong>' . Html::escape($section_title) . '</strong>',
          '#attributes' => [
            'class' => ['kemke-manuals__section'],
            'style' => 'margin-top: 1.5rem; font-weight: 700;',
          ],
        ];
        continue;
      }

      if (!is_string($title) || trim($title) === '' || !is_string($target) || trim($target) === '') {
        continue;
      }

      $target = trim($target);
      $url = str_starts_with($target, 'http://') || str_starts_with($target, 'https://')
        ? Url::fromUri($target)
        : Url::fromUserInput('/' . ltrim($target, '/'));

      $items[] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kemke-manuals__link'],
          'style' => 'margin-top: 0.5rem;',
        ],
        'link' => Link::fromTextAndUrl($title, $url)->toRenderable(),
      ];
    }

    if ($items === []) {
      throw new NotFoundHttpException('Manual links are missing.');
    }

    return [
      'links' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kemke-manuals'],
        ],
        ...$items,
      ],
      '#cache' => [
        'contexts' => ['user.roles'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Returns the resources for the current role.
   *
   * @param array<string, array<int|string, string>> $links_per_role
   *   Configured resources grouped by role.
   *
   * @return array<int|string, string>
   *   Resources keyed by label with URL as value, with integer-keyed section
   *   titles.
   */
  private function resolveRoleLinks(array $links_per_role): array {
    foreach ($this->account->getRoles() as $role) {
      if (isset($links_per_role[$role]) && is_array($links_per_role[$role])) {
        return $links_per_role[$role];
      }
    }

    return isset($links_per_role['default']) && is_array($links_per_role['default'])
      ? $links_per_role['default']
      : [];
  }

  /**
   * Normalizes settings structure for backward compatibility.
   *
   * @param array<mixed> $links_per_role
   *   Raw settings data.
   *
   * @return array<string, array<int|string, string>>
   *   Normalized links grouped by role.
   */
  private function normalizeLinksPerRole(array $links_per_role): array {
    $normalized = [];

    foreach ($links_per_role as $role => $links) {
      if (!is_string($role)) {
        continue;
      }

      // Legacy format: role => "/manuals/file.pdf".
      if (is_string($links) && trim($links) !== '') {
        $normalized[$role] = ['Εγχειρίδιο' => trim($links)];
        continue;
      }

      if (!is_array($links)) {
        continue;
      }

      $normalized[$role] = [];
      foreach ($links as $title => $target) {
        if (is_int($title)) {
          if (is_string($target) && trim($target) !== '') {
            $normalized[$role][] = trim($target);
          }
          continue;
        }

        if (!is_string($title) || trim($title) === '' || !is_string($target) || trim($target) === '') {
          continue;
        }

        $normalized[$role][trim($title)] = trim($target);
      }
    }

    return $normalized;
  }

}
