<?php

declare(strict_types=1);

namespace Drupal\Tests\wudo_license;

use Drupal\wudo_license\Paddle\PaddleClient;

/**
 * Answers transaction requests from a list instead of Paddle's API.
 */
class FakePaddleClient extends PaddleClient {

  /**
   * Transactions by ID, as Paddle's API returns them.
   */
  public static array $transactions = [];

  /**
   * How many times the API was asked.
   */
  public static int $calls = 0;

  /**
   * {@inheritdoc}
   */
  public function transaction(string $id): array {
    static::$calls++;
    if (!isset(static::$transactions[$id])) {
      throw new \RuntimeException('Paddle is not answering.');
    }
    return static::$transactions[$id];
  }

}
