<?php

namespace XaniaShield\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use XaniaShield\Laravel\ShieldClient;

/**
 * A validation rule that runs the current request through Xania Shield.
 *
 * Usage in a Form Request or validate() call:
 *
 *   use XaniaShield\Laravel\Rules\ShieldRule;
 *
 *   $request->validate([
 *       'email'   => ['required', 'email', new ShieldRule('contact')],
 *       'message' => ['required', 'string'],
 *   ]);
 *
 * The rule analyses the whole request (not just the single field it is attached
 * to), so attach it to one field only — typically 'email'. On a block verdict
 * it fails validation with a generic message.
 */
class ShieldRule implements ValidationRule
{
    public function __construct(
        private ?string $formId = null,
        private ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var ShieldClient $shield */
        $shield = App::make(ShieldClient::class);

        if (! $shield->configured()) {
            return; // Not set up — don't block validation.
        }

        $request = Request::instance();

        $verdict = $shield->checkRequest($request, array_filter([
            'email'           => $request->input('email'),
            'content'         => $request->input('message') ?? $request->input('content'),
            'name'            => $request->input('name'),
            'subject'         => $request->input('subject'),
            'form_identifier' => $this->formId ?? $request->path(),
        ], fn ($v) => $v !== null));

        if ($verdict->isBlocked()) {
            $fail($this->message ?? 'This submission could not be processed. Please try again.');
        }
    }
}
