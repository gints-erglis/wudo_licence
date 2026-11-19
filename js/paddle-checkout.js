/**
 * @file
 * Opens Paddle's checkout from a "Buy" button.
 *
 * Paddle.js comes from Paddle's CDN only when a button is pressed, so no
 * request goes to Paddle before the visitor chooses to buy.
 */
((Drupal, drupalSettings, once) => {
  let paddleReady;

  /**
   * Loads and initializes Paddle.js once.
   *
   * @param {{token: string, environment: string}} settings
   *   The client-side token and "sandbox" or "production".
   * @return {Promise<object>}
   *   The Paddle object.
   */
  function loadPaddle(settings) {
    paddleReady ??= new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
      script.addEventListener('load', () => {
        if (settings.environment === 'sandbox') {
          window.Paddle.Environment.set('sandbox');
        }
        window.Paddle.Initialize({ token: settings.token });
        resolve(window.Paddle);
      });
      script.addEventListener('error', () => {
        paddleReady = undefined;
        reject(new Error('Paddle.js did not load.'));
      });
      document.head.append(script);
    });
    return paddleReady;
  }

  Drupal.behaviors.wudoLicensePaddleCheckout = {
    attach(context) {
      const settings = drupalSettings.wudoLicensePaddle;
      if (!settings) {
        return;
      }
      once('wudo-license-buy', '[data-paddle-price]', context).forEach(
        (button) => {
          button.hidden = false;
          button.addEventListener('click', async () => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            try {
              const paddle = await loadPaddle(settings);
              paddle.Checkout.open({
                items: [{ priceId: button.dataset.paddlePrice, quantity: 1 }],
                settings: { successUrl: settings.successUrl },
              });
            } catch (error) {
              new Drupal.Message().add(
                Drupal.t(
                  'The checkout could not be opened. Please try again later.',
                ),
                { type: 'error' },
              );
            } finally {
              button.disabled = false;
              button.removeAttribute('aria-busy');
            }
          });
        },
      );
    },
  };
})(Drupal, drupalSettings, once);
