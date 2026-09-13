# Reporting a security issue

**Please do not open a public issue for a security problem.**

Report it privately, either way:

- **GitHub** — the *Security* tab → *Report a vulnerability*. This opens a
  private advisory only the maintainers can read.
- **E-mail** — [office@ovos.at](mailto:office@ovos.at). Put "php-library
  security" in the subject so it reaches the right desk quickly.

Tell us what you found, how to reproduce it, and which version or commit you
looked at. A proof of concept helps; it does not have to be polished.

## What happens next

- We confirm receipt, normally within a few working days.
- We tell you what we think it is and what we intend to do about it.
- When a fix ships we credit you by name, unless you would rather we did not.

## Scope

This repository is a library. The most interesting attack surface is where it
touches untrusted input or shared infrastructure:

- the cache and its locks (`src/Cache`) — stampede protection, key handling,
  invalidation by tag or version;
- the session handlers (`src/Session`) — identifier handling, per-value locks,
  the searchable index;
- request and form handling (`src/Http`, `src/Form`) — parameter typing,
  redaction, upload handling;
- the query builder and stores (`src/Store`) — anything that could produce an
  unparameterised statement.

Findings in example code, benchmarks or the test fixtures are welcome too, but
they are usually documentation bugs rather than vulnerabilities.
