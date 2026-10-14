// Usage: example <licence file> <computer code> <build date YYYY-MM-DD>
// Prints what the plugin would decide.
//
// Needs license_public_key.h with your public key: put the line printed by
// "drush wudo-license:public-key" into it. Without it the build fails, so a
// placeholder key never ships by mistake.

#include <cstdio>

#include "license_check.h"
#include "license_public_key.h"

int main(int argc, char **argv) {
  if (argc != 4) {
    std::fprintf(stderr, "Usage: %s <licence file> <computer code> <build date>\n", argv[0]);
    return 2;
  }
  license::Info info;
  license::Status status = license::checkFile(argv[1], kLicensePublicKey, "reverb-pro", argv[2], argv[3], &info);
  const char *names[] = {"Valid", "Missing", "Malformed", "BadSignature", "WrongProduct", "WrongMachine", "BuildTooNew", "UnknownFormat"};
  std::printf("%s", names[static_cast<int>(status)]);
  if (status == license::Status::Valid) {
    std::printf(": licensed to %s, updates until %s", info.licensee.c_str(), info.updatesUntil.c_str());
  }
  std::printf("\n");
  return status == license::Status::Valid ? 0 : 1;
}
