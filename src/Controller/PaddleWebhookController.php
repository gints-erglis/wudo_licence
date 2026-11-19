<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\wudo_license\Paddle\PaddleClient;
use Drupal\wudo_license\Paddle\PaddleWebhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Paddle's webhooks.
 *
 * Any answer but 2xx makes Paddle deliver the event again later, so a
 * failure that may pass (Paddle's API not answering, a lock) gets a 500.
 */
final class PaddleWebhookController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected PaddleClient $paddle,
    protected PaddleWebhook $webhook,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Checks the signature and hands the event on.
   */
  public function receive(Request $request): Response {
    $body = $request->getContent();
    if (!$this->paddle->verifySignature((string) $request->headers->get('Paddle-Signature', ''), $body)) {
      $this->loggerFactory->get('wudo_license')->warning('A Paddle webhook with a wrong or old signature was refused.');
      return new Response('', Response::HTTP_UNAUTHORIZED);
    }
    $event = json_decode($body, TRUE);
    if (!is_array($event)) {
      return new Response('', Response::HTTP_BAD_REQUEST);
    }
    try {
      $this->webhook->process($event);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('wudo_license')->error('Paddle event @event (@type) failed and will be retried: @message', [
        '@event' => $event['event_id'] ?? '?',
        '@type' => $event['event_type'] ?? '?',
        '@message' => $e->getMessage(),
      ]);
      return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new Response('', Response::HTTP_OK);
  }

}
