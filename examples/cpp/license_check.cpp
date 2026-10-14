#include "license_check.h"

#include <cstdint>
#include <fstream>
#include <map>
#include <sstream>
#include <vector>

extern "C" {
#include "monocypher-ed25519.h"
}

namespace license {
namespace {

bool hexToBytes(const std::string &hex, std::vector<uint8_t> &out) {
  if (hex.size() % 2 != 0) return false;
  out.clear();
  for (size_t i = 0; i < hex.size(); i += 2) {
    int value = 0;
    for (size_t j = i; j < i + 2; ++j) {
      char c = hex[j];
      value <<= 4;
      if (c >= '0' && c <= '9') value |= c - '0';
      else if (c >= 'a' && c <= 'f') value |= c - 'a' + 10;
      else if (c >= 'A' && c <= 'F') value |= c - 'A' + 10;
      else return false;
    }
    out.push_back(static_cast<uint8_t>(value));
  }
  return true;
}

// A YYYY-MM-DD date; these compare correctly as strings.
bool isDate(const std::string &s) {
  if (s.size() != 10 || s[4] != '-' || s[7] != '-') return false;
  for (size_t i : {0, 1, 2, 3, 5, 6, 8, 9}) {
    if (s[i] < '0' || s[i] > '9') return false;
  }
  return true;
}

}  // namespace

Status check(const std::string &fileText,
             const unsigned char publicKey[32],
             const std::string &product,
             const std::string &machine,
             const std::string &buildDate,
             Info *info) {
  // The signature covers everything before the "signature=" line.
  // Windows editors may have turned "\n" into "\r\n"; undo that first.
  std::string text;
  text.reserve(fileText.size());
  for (char c : fileText) {
    if (c != '\r') text += c;
  }
  const std::string marker = "signature=";
  size_t at = text.rfind(marker);
  if (at == std::string::npos || (at > 0 && text[at - 1] != '\n')) return Status::Malformed;
  const std::string message = text.substr(0, at);

  std::string signatureHex = text.substr(at + marker.size());
  while (!signatureHex.empty() && (signatureHex.back() == '\n' || signatureHex.back() == ' ')) {
    signatureHex.pop_back();
  }
  std::vector<uint8_t> signature;
  if (signatureHex.size() != 128 || !hexToBytes(signatureHex, signature)) return Status::Malformed;

  if (crypto_ed25519_check(signature.data(), publicKey,
                           reinterpret_cast<const uint8_t *>(message.data()),
                           message.size()) != 0) {
    return Status::BadSignature;
  }

  // Only now that the text is known to be genuine, read it.
  std::map<std::string, std::string> values;
  std::istringstream lines(message);
  std::string line;
  while (std::getline(lines, line)) {
    size_t eq = line.find('=');
    if (eq == std::string::npos) return Status::Malformed;
    values[line.substr(0, eq)] = line.substr(eq + 1);
  }

  if (values["format"] != "1") return Status::UnknownFormat;
  if (!isDate(values["updates_until"]) || !isDate(buildDate)) return Status::Malformed;

  if (info) {
    info->product = values["product"];
    info->license = values["license"];
    info->licensee = values["licensee"];
    info->machine = values["machine"];
    info->updatesUntil = values["updates_until"];
    info->issued = values["issued"];
  }

  if (values["product"] != product) return Status::WrongProduct;
  if (values["machine"] != machine) return Status::WrongMachine;
  if (buildDate > values["updates_until"]) return Status::BuildTooNew;
  return Status::Valid;
}

Status checkFile(const std::string &path,
                 const unsigned char publicKey[32],
                 const std::string &product,
                 const std::string &machine,
                 const std::string &buildDate,
                 Info *info) {
  std::ifstream in(path, std::ios::binary);
  if (!in) return Status::Missing;
  std::ostringstream text;
  text << in.rdbuf();
  if (text.str().size() > 16384) return Status::Malformed;
  return check(text.str(), publicKey, product, machine, buildDate, info);
}

}  // namespace license
