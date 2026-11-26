<?php

namespace App\Console\Commands;

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\NikniqController;

/** Configuration mistakes that break sign-in. Shared by ops:oauth and ops:verify-deploy. */
class OAuthChecks
{
    /** @return list<string> */
    public static function problems(): array
    {
        $out = [];
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (config('services.google.client_id') && ! config('services.google.client_secret')) {
            $out[] = 'GOOGLE_CLIENT_ID is set but GOOGLE_CLIENT_SECRET is empty — the Google button stays hidden.';
        }

        if (NikniqController::enabled()) {
            $uri = NikniqController::redirectUri();
            $host = parse_url($uri, PHP_URL_HOST);
            if ($host && $appHost && $host !== $appHost) {
                $out[] = "NIKNIQ_REDIRECT_URI host ($host) differs from APP_URL host ($appHost) — the session will not survive the round trip.";
            }
            if (app()->environment('production') && ! str_starts_with($uri, 'https://')) {
                $out[] = "NIKNIQ_REDIRECT_URI is not https in production: $uri";
            }
        }

        if (GoogleController::enabled() && app()->environment('production')
            && str_starts_with((string) config('services.google.redirect'), 'http://')) {
            $out[] = 'GOOGLE_REDIRECT_URI is http:// in production; Google rejects non-https redirects.';
        }

        return $out;
    }
}
