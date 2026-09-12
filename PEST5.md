# Pest 5 Setup Guide

This project is now fully configured with Pest 5 and all its plugins. Here's how to use the new features:

## ✅ Installed Plugins

- **pest-plugin-phpstan** - Type checking for tests
- **pest-plugin-agent** - AI agent testing
- **pest-plugin-evals** - LLM evaluation
- **pest-plugin-rector** - Automated refactoring
- **pest-plugin-arch** - Architecture testing
- **pest-plugin-browser** - Browser testing
- **pest-plugin-laravel** - Laravel integration
- **pest-plugin-mutate** - Mutation testing
- **pest-plugin-type-coverage** - Type coverage
- **pest-plugin-profanity** - Profanity checking

## 🚀 New Features

### 1. Test Impact Analysis (Tia Engine) ⚡

Run only tests affected by your changes:

```bash
./vendor/bin/pest --tia
```

**Real performance on this project:**
- Without Tia: 9.5 seconds (221 tests)
- With Tia: 0.4 seconds (221 tests replayed)
- **22x faster!** 🚀

First run records dependencies, subsequent runs only execute affected tests. Requires PCOV or Xdebug (both installed).

**Note:** Tia cache is in `tests/.pest/` and is gitignored for local development only.

### 2. PHPStan Integration

PHPStan now understands Pest's functional API:

```bash
./vendor/bin/phpstan analyze --level 8 tests/
```

The plugin is already configured in `phpstan.neon`.

### 3. Rector for Tests

Auto-refactor tests to use Pest's expressive matchers:

```bash
# Preview changes
./vendor/bin/rector process tests/ --dry-run

# Apply changes
./vendor/bin/rector process tests/
```

Rector is configured with `PestSetList::CODING_STYLE` in `rector.php`.

### 4. Agent Plugin

AI agents can verify code with a single command:

```bash
# Test a simple assertion
./vendor/bin/pest --agent='$user = \App\Models\User::factory()->create(); expect($user->name)->toBeString();'

# Test with browser
./vendor/bin/pest --agent='visit("/")->assertSee("Welcome");'
```

### 5. Evals for LLM Testing

Evaluate AI-generated content quality:

```php
it('generates relevant content', function (): void {
    $output = YourAI::generate('Tell me about Laravel');
    
    expect($output)
        ->toContain('PHP framework')    // deterministic
        ->toBeRelevant()                // LLM-as-judge
        ->toBeSimilar('Laravel is...'); // semantic similarity
});
```

Run evals (skipped by default to avoid API costs):

```bash
./vendor/bin/pest --evals
```

### 6. Time-Balanced Sharding

Split tests across CI machines by execution time:

```bash
# Generate timing data
./vendor/bin/pest --update-shards

# Run a shard
./vendor/bin/pest --shard=1/4
```

Commit `tests/.pest/shards.json` to your repository.

### 7. New Expectations

```php
expect('nuno@pestphp.com')->toBeEmail();
expect('01ARZ3NDEKTSV4RRFFQ69G5FAV')->toBeUlid();
expect('192.168.1.1')->toBeIpAddress();
expect('00:1a:2b:3c:4d:5e')->toBeMacAddress();
expect('example.com')->toBeHostname();
expect('example.co.uk')->toBeDomain();
expect('Zm9vYmFy')->toBeBase64();
expect('deadbeef')->toBeHexadecimal();
```

## 📚 Documentation

- [Pest 5 Announcement](https://pestphp.com/docs/pest5-now-available)
- [Tia Engine](https://pestphp.com/docs/tia)
- [Agent Plugin](https://pestphp.com/docs/agent)
- [Evals](https://pestphp.com/docs/evals)
- [PHPStan](https://pestphp.com/docs/phpstan)
- [Rector](https://pestphp.com/docs/rector)

## 🎯 Recommended Workflow

1. **Local Development**: Use `--tia` for fast iteration
   ```bash
   ./vendor/bin/pest --tia
   ```

2. **Before Commit**: Run PHPStan on tests
   ```bash
   ./vendor/bin/phpstan analyze tests/
   ```

3. **Code Review**: Run Rector to improve test quality
   ```bash
   ./vendor/bin/rector process tests/ --dry-run
   ```

4. **CI Pipeline**: Always run full suite
   ```bash
   ./vendor/bin/pest --parallel
   ```

## ⚡ Performance Tips

- Tia Engine can reduce 10-minute suites to ~4 seconds locally
- Time-balanced sharding ensures all CI workers finish simultaneously
- Browser tests work with Agent plugin for full-stack verification
