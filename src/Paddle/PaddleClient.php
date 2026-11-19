<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Paddle;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;

/**
 * Paddle Billing: the settings, webhook signatures and the API calls needed.
 *
 * Everything comes from one setting, so that a development site uses the
 * sandbox and the production site the live account:
 *
 * @code
 * $settings['wudo_license_paddle'] = [
 *   'environment' => 'sandbox',
 *   'client_token' => getenv('PADDLE_CLIENT_TOKEN'),
 *   'api_key' => getenv('PADDLE_API_KEY'),
 *   'webhook_secret' => getenv('PADDLE_WEBHOOK_SECRET'),
 * ];
 * @endcode
 */
class PaddleClient {

  /**
   * Name of the setting.
   */
  const SETTING = 'wudo_license_paddle';

  /**
   * How far, in seconds, a webhook's signing time may be from now.
   */
  const TOLERANCE = 300;

  public function __construct(
    protected ClientInterface $httpClient,
    protected TimeInterface $time,
  ) {}

  /**
   * Whether the setting is there at all.
   */
  public function isEnabled(): bool {
    return (bool) Settings::get(self::SETTING);
  }

  /**
   * The environment: "production", or "sandbox" for anything else.
   */
  public function environment(): string {
    return $this->value('environment') === 'production' ? 'production' : 'sandbox';
  }

  /**
   * The client-side token for Paddle.js; public by design.
   */
  public function clientToken(): string {
    return $this->value('client_token');
  }

  /**
   * The names of the values that are missing for selling.
   *
   * @return string[]
   *   Empty when checkout and webhooks can work.
   */
  public function missing(): array {
    return array_values(array_filter(
      ['client_token', 'api_key', 'webhook_secret'],
      fn (string $name): bool => $this->value($name) === '',
    ));
  }

  /**
   * A button that opens Paddle's checkout for one price.
   *
   * Paddle.js is loaded from Paddle only when the button is pressed, so the
   * page makes no request to Paddle before the visitor chooses to buy. The
   * button is hidden until the script shows it: without the script it could
   * do nothing.
   *
   * @param string $price
   *   The Paddle price ID.
   * @param string|\Stringable $label
   *   The button text.
   *
   * @return array
   *   A render array; empty when there is no client-side token.
   */
  public function checkoutButton(string $price, string|\Stringable $label): array {
    if ($price === '' || $this->clientToken() === '') {
      return [];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $label,
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--primary', 'wudo-license-buy'],
        'data-paddle-price' => $price,
        'hidden' => 'hidden',
      ],
      '#attached' => $this->checkoutAttachments(),
    ];
  }

  /**
   * The script and its settings for opening Paddle's checkout.
   */
  public function checkoutAttachments(): array {
    return [
      'library' => ['wudo_license/paddle_checkout'],
      'drupalSettings' => [
        'wudoLicensePaddle' => [
          'token' => $this->clientToken(),
          'environment' => $this->environment(),
          'successUrl' => Url::fromRoute('wudo_license.downloads', [], [
            'query' => ['purchased' => 1],
            'absolute' => TRUE,
          ])->toString(),
        ],
      ],
    ];
  }

  /**
   * Checks the Paddle-Signature header of a webhook against its raw body.
   *
   * The header is "ts=<unix time>;h1=<hex HMAC-SHA256>", with more than one
   * h1 while a secret is being rotated. The HMAC covers "<ts>:<body>".
   */
  public function verifySignature(string $header, string $body): bool {
    $secret = $this->value('webhook_secret');
    if ($secret === '') {
      return FALSE;
    }
    $timestamp = '';
    $hashes = [];
    foreach (explode(';', $header) as $part) {
      [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
      if ($name === 'ts') {
        $timestamp = $value;
      }
      elseif ($name === 'h1') {
        $hashes[] = $value;
      }
    }
    if (!ctype_digit($timestamp) || !$hashes || abs($this->time->getCurrentTime() - (int) $timestamp) > self::TOLERANCE) {
      return FALSE;
    }
    $expected = hash_hmac('sha256', $timestamp . ':' . $body, $secret);
    foreach ($hashes as $hash) {
      if (hash_equals($expected, $hash)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns a transaction with its customer and business.
   *
   * Webhooks carry only IDs; the e-mail address and the name for the licence
   * come from here.
   *
   * @throws \RuntimeException
   *   When there is no API key or Paddle does not answer as expected.
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function transaction(string $id): array {
    $key = $this->value('api_key');
    if ($key === '') {
      throw new \RuntimeException('No Paddle API key: set $settings[\'' . self::SETTING . '\'][\'api_key\'].');
    }
    $base = $this->environment() === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com';
    $response = $this->httpClient->request('GET', $base . '/transactions/' . rawurlencode($id), [
      'query' => ['include' => 'customer,business'],
      'headers' => [
        'Authorization' => 'Bearer ' . $key,
        'Paddle-Version' => '1',
        'Accept' => 'application/json',
      ],
      'timeout' => 10,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || !is_array($data['data'] ?? NULL)) {
      throw new \RuntimeException('Unexpected answer from Paddle for transaction ' . $id . '.');
    }
    return $data['data'];
  }

  /**
   * One value of the setting, as a string.
   */
  protected function value(string $name): string {
    $setting = Settings::get(self::SETTING);
    return is_array($setting) && is_scalar($setting[$name] ?? NULL) ? trim((string) $setting[$name]) : '';
  }

}
