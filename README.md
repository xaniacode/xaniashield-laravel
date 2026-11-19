# Xania Shield for Laravel

Privacy-first spam protection for Laravel forms. This is the official Laravel client for the [Xania Shield](https://xaniashield.com) API — block bots and spam across your forms without CAPTCHAs, without Google, and without sending visitor data to third-party AI services.

- **No CAPTCHAs** — works invisibly in the background
- **Privacy-first** — statistical analysis on Xania Shield's servers in the United States, no Big Tech ([privacy policy](https://xaniashield.com/privacy))
- **Fail-open by design** — if the API is unreachable, your forms keep working
- **Drop-in** — one line per form, with optional honeypot and timing signals

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- A Xania Shield API key (create one free at [app.xaniashield.com](https://app.xaniashield.com))

## Installation

```bash
composer require xaniashield/laravel
```

The service provider and `Shield` facade are auto-discovered — no manual registration needed.

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=shield-config
```

Add your credentials to `.env`:

```env
SHIELD_API_KEY=xshd_live_your_key_here
SHIELD_FAIL_OPEN=true
# Optional, only if you use the timing signal:
SHIELD_TIMING_SECRET=a-long-random-string
```

## Usage

### Basic — analyze a submission

Inject the client (or use the `Shield` facade) in any controller:

```php
use XaniaShield\Laravel\ShieldClient;

public function submit(Request $request, ShieldClient $shield)
{
    $verdict = $shield->checkRequest($request, [
        'email'           => $request->input('email'),
        'content'         => $request->input('message'),
        'form_identifier' => 'contact',
    ]);

    if ($verdict->isBlocked()) {
        // Silently drop, show an error, whatever fits your form.
        return back()->with('status', 'Message sent.');
    }

    // allow or challenge — proceed normally
}
```

### With the facade

```php
use XaniaShield\Laravel\Facades\Shield;

$verdict = Shield::checkRequest($request, [
    'email'           => $request->input('email'),
    'form_identifier' => 'newsletter',
]);
```

### The verdict object

```php
$verdict->action();        // 'allow' | 'challenge' | 'block'
$verdict->score();         // 0-100
$verdict->isAllowed();     // bool
$verdict->isBlocked();     // bool
$verdict->shouldChallenge(); // bool
$verdict->reasons();       // string[]
$verdict->requestId();     // string|null
$verdict->failedOpen();    // bool — true if the API was unreachable
```

## Honeypot

A honeypot is a hidden field that bots fill and humans never touch. Add one to your form:

```blade
<input type="text" name="website_url" tabindex="-1" autocomplete="off"
       style="position:absolute;left:-9999px" aria-hidden="true">
```

The client reads the configured honeypot field automatically (default `website_url`). To use a different field name, pass it explicitly:

```php
$verdict = $shield->checkRequest($request, [
    'email'          => $request->input('email'),
    'honeypot_field' => 'my_hp_field',
    'honeypot_value' => $request->input('my_hp_field', ''),
]);
```

## Timing signal (optional)

The timing signal measures how long a form took to fill — bots submit near-instantly. It requires `SHIELD_TIMING_SECRET` to be set.

Render a signed timestamp field in your form:

```blade
{!! app(\XaniaShield\Laravel\ShieldClient::class)->timingField() !!}
```

The client verifies and includes the elapsed time automatically on the next `checkRequest()`.

## Fail-open vs fail-closed

By default (`SHIELD_FAIL_OPEN=true`), if the API is unreachable, times out, or errors, submissions are **allowed** — protection never breaks your forms. Set `SHIELD_FAIL_OPEN=false` to block on uncertainty instead (stricter, but a Shield outage would block submissions).

## Health check

```php
Shield::configured(); // has an API key + https base URL
Shield::health();     // API reachable and key valid
```

## Configuration reference

All values are read from `config/shield.php` (env-driven):

| Key | Env | Default | Purpose |
|---|---|---|---|
| `api_key` | `SHIELD_API_KEY` | `''` | Your site API key |
| `base_url` | `SHIELD_BASE_URL` | `https://xaniashield.com/v1` | API base URL |
| `timeout` | `SHIELD_TIMEOUT` | `5` | Request timeout (seconds) |
| `fail_open` | `SHIELD_FAIL_OPEN` | `true` | Allow on API failure |
| `timing_secret` | `SHIELD_TIMING_SECRET` | `''` | HMAC secret for timing |
| `challenge_threshold` | `SHIELD_CHALLENGE_THRESHOLD` | `40` | Score for challenge |
| `block_threshold` | `SHIELD_BLOCK_THRESHOLD` | `70` | Score for block |
| `honeypot_field` | `SHIELD_HONEYPOT_FIELD` | `website_url` | Honeypot field name |
| `timing_field` | `SHIELD_TIMING_FIELD` | `xsh_tf` | Timing field name |

## Integration recipes

Forms differ across projects — field names, honeypots, Livewire vs controllers. Pick the entry point that fits. All of them ultimately call the same engine; choose by how much control you want.

### 1. Middleware (simplest — protect a whole route)

For standard forms with `email` / `message` / `name` / `subject` fields:

```php
Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('shield:contact');
```

On a block verdict it aborts with HTTP 422 before reaching your controller. No controller changes needed. For non-standard field names, use one of the options below instead.

### 2. Validation rule (idiomatic — fits existing validation)

```php
use XaniaShield\Laravel\Rules\ShieldRule;

$request->validate([
    'email'   => ['required', 'email', new ShieldRule('contact')],
    'message' => ['required', 'string', 'max:5000'],
]);
```

Attach the rule to **one** field only (usually `email`) — it analyses the whole request, not just that field. A block fails validation with a generic message you can customise: `new ShieldRule('contact', 'Your message looks like spam.')`.

### 3. Controller call (most control — custom field mapping)

When your fields are non-standard, map them explicitly:

```php
use XaniaShield\Laravel\Facades\Shield;

public function submit(Request $request)
{
    $verdict = Shield::checkRequest($request, [
        'email'           => $request->input('contact_email'),   // custom name
        'content'         => $request->input('enquiry_body'),
        'form_identifier' => 'enquiry',
        'honeypot_field'  => 'company_website',                  // custom honeypot
        'honeypot_value'  => $request->input('company_website', ''),
    ]);

    if ($verdict->isBlocked()) {
        return back()->withErrors(['enquiry_body' => 'Could not send. Please try again.']);
    }

    // proceed
}
```

### 4. Livewire component

```php
use XaniaShield\Laravel\ShieldClient;

public function submit(ShieldClient $shield)
{
    $verdict = $shield->analyze([
        'email'           => $this->email,
        'content'         => $this->message,
        'form_identifier' => 'contact',
        'visitor_ip'      => request()->ip(),
    ]);

    if ($verdict->isBlocked()) {
        $this->addError('message', 'Could not send. Please try again.');
        return;
    }

    // proceed
}
```

Livewire has no per-submit HTTP request for the form fields, so pass values from the component state and add `visitor_ip` explicitly.

### 5. Form Request class

```php
use Illuminate\Foundation\Http\FormRequest;
use XaniaShield\Laravel\Rules\ShieldRule;

class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email'   => ['required', 'email', new ShieldRule('contact')],
            'message' => ['required', 'string'],
        ];
    }
}
```

### 6. API endpoint (JSON)

```php
$verdict = Shield::checkRequest($request, [
    'email'           => $request->input('email'),
    'content'         => $request->input('body'),
    'form_identifier' => 'api-contact',
]);

if ($verdict->isBlocked()) {
    return response()->json(['message' => 'Rejected as spam.'], 422);
}
```

### Honeypot & timing in Blade

Add a honeypot (and optionally a timing field) to any form with directives:

```blade
<form method="POST" action="/contact">
    @csrf
    @shieldHoneypot
    @shieldTiming   {{-- only renders if SHIELD_TIMING_SECRET is set --}}

    <input type="email" name="email">
    <textarea name="message"></textarea>
    <button>Send</button>
</form>
```

`@shieldHoneypot` renders a hidden field named after `config('shield.honeypot_field')`. The client reads it back automatically.

## License

MIT — see [LICENSE](LICENSE).
