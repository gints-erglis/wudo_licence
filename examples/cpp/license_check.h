// Checks a licence file issued by the Drupal module wudo_license.
//
// Needs Monocypher 4 (https://monocypher.org), two of its files:
//   src/monocypher.c, src/monocypher.h
//   src/optional/monocypher-ed25519.c, src/optional/monocypher-ed25519.h
// Ed25519 there is the same as libsodium's, which the website signs with.
//
// This is a starting point, not a hardened implementation: read
// README.md next to it for what is left to the plugin.

#pragma once

#include <string>

namespace license {

enum class Status {
  Valid,
  Missing,          // no file, or it cannot be read
  Malformed,        // not a licence file
  BadSignature,     // changed, or not issued by the website
  WrongProduct,     // issued for another product
  WrongMachine,     // issued for another computer
  BuildTooNew,      // this build is newer than the licence's updates
  UnknownFormat,    // made by a newer website version
};

struct Info {
  std::string product;
  std::string license;
  std::string licensee;      // show it in the plugin: "Licensed to ..."
  std::string machine;
  std::string updatesUntil;  // YYYY-MM-DD
  std::string issued;        // YYYY-MM-DD
};

// Checks the text of a licence file.
//
// publicKey: the 32 bytes printed by "drush wudo-license:public-key".
// product:   the product code, as set on the website.
// machine:   this computer's code, as the plugin shows it to the customer.
// buildDate: this build's date, YYYY-MM-DD; a build newer than the end of
//            the licence's updates is refused, older builds keep working.
Status check(const std::string &fileText,
             const unsigned char publicKey[32],
             const std::string &product,
             const std::string &machine,
             const std::string &buildDate,
             Info *info = nullptr);

// Reads a file and checks it; Missing when it cannot be read.
Status checkFile(const std::string &path,
                 const unsigned char publicKey[32],
                 const std::string &product,
                 const std::string &machine,
                 const std::string &buildDate,
                 Info *info = nullptr);

}  // namespace license
