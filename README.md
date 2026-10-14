# Wudo License

A Drupal 11 module for selling software by licence, with the purchase done
outside the website (by invoice). The customer gets a licence key, opens
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

Kernel tests in `tests/src/Kernel`:

```bash
vendor/bin/phpunit web/modules/custom/wudo_license
```
