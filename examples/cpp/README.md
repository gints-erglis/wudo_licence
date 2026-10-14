# Checking a licence file in C++

The files in this directory are under the BSD Zero Clause licence
(`LICENSE`): copy them into your software, open source or not, without
conditions.

`license_check.h` and `license_check.cpp` check a licence file issued by the
website. `example.cpp` is a small command line program around them.

## Building

Add [Monocypher 4](https://monocypher.org) to the plugin: `monocypher.c`,
`monocypher.h`, and from `optional/` `monocypher-ed25519.c` and
`monocypher-ed25519.h`. Its Ed25519 is the same as libsodium's, which the
website signs with. Monocypher is public domain (or BSD-2-Clause, as you
prefer) and has no dependencies.

```bash
cc  -O2 -c monocypher.c -o monocypher.o
cc  -O2 -c optional/monocypher-ed25519.c -I. -o monocypher-ed25519.o
c++ -std=c++17 -O2 -I. -Ioptional license_check.cpp example.cpp \
    monocypher.o monocypher-ed25519.o -o example
./example reverb-pro.lic PC-ONE-1234 2026-06-01
```

Before building, create `license_public_key.h` with the C array line printed
by `drush wudo-license:public-key`. The example does not build without it,
so a placeholder key cannot be shipped by mistake.

## What the check does

1. Takes the text before the `signature=` line exactly as it is (after
   removing `\r`, which Windows tools sometimes add) and verifies the
   signature with the public key compiled into the plugin. Any change to
   the file fails here.
2. Only then reads the values, and refuses a file for another product,
   another computer, or a newer `format`.
3. Compares the build date of the plugin with `updates_until`: a build made
   after the end of the licence's updates is refused; older builds keep
   working forever. The computer's clock is not used, so changing it gains
   nothing.

## What is left to the plugin

- **The computer code.** Make it stable (it must not change on a restart or
  an update of the host program) and show it to the customer when there is
  no valid licence. What to base it on depends on the platform; avoid
  things that change, such as network addresses.
- **The build date.** Set it at build time, e.g. `-DBUILD_DATE="2026-10-14"`
  from the build script, and give each release on the website the same date
  as "Released".
- **Where the licence file lives**, and a way for the customer to load it.
- **Hardening.** Anyone who edits the binary can remove the check. Calling it
  from more than one place and signing the binary makes that harder, not
  impossible. The signature stops everything else: sharing a file with a
  colleague, publishing a key, editing the date.
