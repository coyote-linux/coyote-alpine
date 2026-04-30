# tests

## OVERVIEW

PHPUnit tests for runtime libraries and boot/config integration assumptions. This directory has real tests; any guidance saying tests are empty is stale.

## STRUCTURE

```
tests/
├── phpunit.xml      # suite config
├── bootstrap.php    # autoloader and test constants
├── unit/            # focused class/service tests
└── integration/     # filesystem/boot/config lifecycle tests
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Runner config | `phpunit.xml` | Unit + Integration suites |
| Bootstrap/path constants | `bootstrap.php` | loads `../rootfs/opt/coyote/lib/autoload.php` |
| Config tests | `unit/ConfigLoaderTest.php`, `integration/ConfigApplyTest.php` | temp dirs and cleanup |
| Boot layout tests | `integration/BootSequenceTest.php` | hardcoded initramfs file expectations |
| Service tests | `unit/FirewallManagerTest.php`, `unit/HaproxyServiceTest.php`, `unit/NetworkTest.php` | skip external dependencies when needed |

## CONVENTIONS

- Test classes use `Coyote\Tests\Unit` or `Coyote\Tests\Integration` namespaces.
- File names end with `Test.php`; methods use `test*` naming.
- Temp paths use `sys_get_temp_dir()` and often include PID to avoid collisions.
- Bootstrap defines `COYOTE_VERSION`, `COYOTE_CONFIG_PATH`, and `COYOTE_RUNNING_CONFIG` if absent.

## ANTI-PATTERNS

- Do not hardcode host `/mnt/config` or `/tmp/running-config` in tests; use temp dirs/constants.
- Do not delete tests to avoid missing runtime tools; skip with a precise reason.
- Do not add config schema fields without tests for defaults/validation/apply behavior.

## COMMANDS

```bash
phpunit -c phpunit.xml
phpunit -c phpunit.xml --testsuite Unit
phpunit -c phpunit.xml --testsuite Integration
```
