# Wudo License

A Drupal 11 module for selling software by licence, either by invoice or as
a one-time purchase through [Paddle](https://www.paddle.com), which as
merchant of record charges, invoices and pays the taxes. The customer gets a licence key, opens
their downloads with it and a code sent by e-mail, downloads the versions
the licence covers, and gets a signed licence file for each computer the
software runs on. There are no customer accounts or passwords.

## How it works

1. **You** add a product (for example a plugin) and its releases, each with
   a file and a release date. When an invoice is paid you add a licence:
   who it is for, their e-mail address, the product, and the day until
   which updates are included. The licence gets a generated key; send it to
   the customer.
2. **The customer** goes to `/downloads` and enters the key. A six-digit code
   goes to the licence's e-mail address; with it the downloads open in that
   browser for 30 minutes.
3. **The downloads page** lists the releases. Those released up to the end
   of the licence's updates can be downloaded; later ones are listed as
   needing a renewal.
4. **Activation.** The software, without a licence file, shows its computer
   code. The customer enters it on the downloads page and gets a licence
   file for that computer. Each licence allows a number of computers (2 by
   default); asking again for the same computer does not use another.
5. **Renewal.** When the next year is paid, extend "Updates until". The
   customer downloads the new versions and a new licence file. Old versions
   keep working with the old file.

Customers are reminded by e-mail 30 and 7 days before their updates end,
with a copy to the site's e-mail address.

## The licence file

Plain text, one value per line, signed with Ed25519:

```
format=1
product=reverb-pro
license=K7QM2-XHDP9-TRWC4-N8VBE-QJ3MA
licensee=SIA Example
machine=4F2A-99C1
updates_until=2027-10-14
issued=2026-10-14
signature=<128 hex digits>
```

The signature covers every byte before the `signature=` line. The software
holds the public key and checks the signature, the product, the computer,
and that its own build date is not later than `updates_until`. It never
contacts the website. `examples/cpp` has a C++ implementation with
Monocypher, tested against files issued by this module.

## Installing

The module is not on drupal.org. In the site's `composer.json`, add the
repository and require it:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/gints-erglis/wudo_licence.git" }
]
```

```bash
composer require gints-erglis/wudo_license
drush en wudo_license
```

Then three settings, in `settings.php` or, on a DDEV production server, in
`settings.local.php` with the value in `.ddev/.env`:

```php
// Release files are kept here, outside the web root.
$settings['file_private_path'] = $app_root . '/../private';
// The secret signing key; see below.
$settings['wudo_license_signing_key'] = getenv('LICENSE_SIGNING_KEY');
```

Add `/private/` to `.gitignore`. `/admin/reports/status` says whether both
are in place.

## The signing key

```bash
drush wudo-license:keygen
```

prints a new secret key and its public key. Nothing is stored.

- The **secret key** goes into the setting above (e.g. `LICENSE_SIGNING_KEY`
  in `.ddev/.env` on the server) and into a password manager. Whoever has it
  can issue licence files, so it never goes into Git or the database.
- The **public key** is compiled into the software;
  `drush wudo-license:public-key` prints it again, also as a C array.
- **Losing the secret key** means no new licence files until a new key pair
  is made, and then every customer needs a version of the software with the
  new public key. Keep the copy.

## Backups

The database holds the licences and activations; release files are in the
private directory. A backup needs both. The starter's `ddev backup` copies
`web/sites/default/files` only, so add the private directory to it, or back
it up separately.

## Administration

Content → Licences, with tabs for products and releases. The permission
"Administer software licences" gives access to all of it, including keys and
e-mail addresses; give it to trusted roles only.

- **A customer replaces a computer:** open the licence and "Free" the old
  one. Its licence file keeps working, since the software does not ask the
  website; that is the price of working offline.
- **Revoking** a licence stops downloads and new licence files. Files already
  issued keep working, for the same reason.
- **Deleting** a licence deletes its activations.

Settings without a form (`drush config:edit wudo_license.settings`):
computers per new licence, minutes a code is valid, minutes the downloads
stay open, and the reminder days.

## Selling with Paddle

Paddle sells each product for a one-time payment, which brings a year of
updates; the site follows by webhook, and nobody has to be there:

1. Every product with a Paddle price has a page of its own,
   `/checkout/{product code}` (e.g. `/checkout/jpeg-repair`): a stable
   address to link to from the software, e-mails and other sites. The
   "Buy a licence (Paddle)" block puts the same button on any other page.
   Only when the button is pressed is Paddle.js loaded from Paddle, and its
   checkout opens over the page.
2. When the payment is complete, Paddle calls `/paddle/webhook`. The module
   asks Paddle's API for the buyer's e-mail address and name (the business
   name, if they gave one), creates a licence with updates until the same
   day a year later, and e-mails the key, with a copy to the site's address.
   The buyer comes back to `/downloads`. A quantity above one gives that
   many times the default number of computers.
3. Before the year ends the customer is reminded, with a link to the
   product's checkout page. Nothing is charged on its own: whoever wants
   the later versions buys again and gets a new licence, with a new key.
   The versions up to the end of the first year keep working with the old
   licence file.

A yearly subscription price works too: its first payment creates the
licence, each renewal extends "Updates until" to the end of the new period
and tells the customer, and no reminders are sent while it renews. Its
checkout says "Subscribe", and the page and e-mail texts are written for a
one-time purchase, so offer one model per site.

Refunds are not acted on: revoke the licence by hand if you want to.

### Setting up

In Paddle (start in the sandbox, <https://sandbox-vendors.paddle.com>):

1. **Catalog → Products:** a product with a one-time price (no billing
   cycle). Note the price ID (`pri_…`).
2. **Developer tools → Authentication:** a client-side token (`test_…` or
   `live_…`) and an API key that may read transactions, customers and
   businesses.
3. **Developer tools → Notifications:** a destination
   `https://<site>/paddle/webhook` with the event `transaction.completed`
   (and, for subscription prices, `subscription.created`,
   `subscription.updated`, `subscription.canceled`, `subscription.past_due`,
   `subscription.paused` and `subscription.resumed`). Note its secret key.
4. **Checkout → Checkout settings:** `https://<site>/checkout` as the
   default payment link, and, for the live account, the domain approved.
   Checkouts that Paddle starts itself (invoices, payment links), for any
   product, open there: the page loads Paddle.js at once when the address
   has `?_ptxn=…`. Without it, the page lists the products for sale.

In the site's settings, with the values in `.ddev/.env`:

```php
$settings['wudo_license_paddle'] = [
  // 'production' for the live account.
  'environment' => 'sandbox',
  'client_token' => getenv('PADDLE_CLIENT_TOKEN'),
  'api_key' => getenv('PADDLE_API_KEY'),
  'webhook_secret' => getenv('PADDLE_WEBHOOK_SECRET'),
];
```

Then add the price ID to the product (Content → Licences → Products); its
checkout page works from then on. Products without a price have none (404).
With more than one price, the page sells the first; the block can sell any.
`/admin/reports/status` says whether the settings are complete and whether
the sandbox or the live account is used. The sandbox and the live account
have different price IDs, tokens and keys.

The page's Content-Security-Policy must allow Paddle: `https://cdn.paddle.com`
for scripts and styles, `https://*.paddle.com` for connections, and
`https://buy.paddle.com` and `https://sandbox-buy.paddle.com` for frames.

To test, buy in the sandbox with Paddle's test card 4242 4242 4242 4242
(any name, a future date, CVC 100). A local site needs a tunnel (for
example `ddev share`) for Paddle to reach the webhook. Paddle's
notification simulator can send a renewal.

Webhooks are refused without a valid signature, or when signed more than
five minutes ago. An event that fails, for example because Paddle's API did
not answer, is answered with an error, so that Paddle sends it again later;
every step can run twice without effect.

## Security notes

- Every key gets the same answer, so trying keys reveals nothing; the e-mail
  address is never shown.
- Keys are tried at most 10 times an hour per address, codes likewise; a code
  stops working after 5 wrong guesses and after 10 minutes; at most 5 codes
  an hour are sent for one licence.
- Codes are stored only as a keyed hash. The downloads page and files are
  never cached and not indexed.
- Release files are served only through the downloads page; their entity is
  visible to administrators only, so `/system/files/...` refuses them.

## Licence

The module is licensed under the GNU General Public License, version 2 or
(at your option) any later version; see `LICENSE`.

The C++ example in `examples/cpp` is under the BSD Zero Clause licence
instead (see `examples/cpp/LICENSE`), so it can be copied into software that
is not open source.

## Development

Kernel tests in `tests/src/Kernel`, the Paddle webhooks with a fake API
client:

```bash
vendor/bin/phpunit web/modules/custom/wudo_license
```
