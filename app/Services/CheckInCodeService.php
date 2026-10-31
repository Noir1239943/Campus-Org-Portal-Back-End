<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Str;

/**
 * Rotating 6-character check-in codes.
 *
 * The code shown under the event QR changes every minute, so a code (or a
 * screenshot of the QR) shared in a group chat stops working quickly. This
 * replaces passing around a paper sign-in sheet.
 */
class CheckInCodeService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I

    public function period(): int
    {
        return max(15, (int) config('campus.check_in_code_seconds', 60));
    }

    public function currentWindow(): int
    {
        return intdiv(time(), $this->period());
    }

    public function secondsRemaining(): int
    {
        return $this->period() - (time() % $this->period());
    }

    public function ensureSecret(Event $event): void
    {
        if (! $event->check_in_secret) {
            $event->check_in_secret = Str::random(40);
            $event->save();
        }
    }

    public function codeFor(Event $event, ?int $window = null): string
    {
        $window ??= $this->currentWindow();
        $hash = hash_hmac('sha256', $event->id.'|'.$window, $event->check_in_secret.'|'.config('app.key'));

        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[hexdec(substr($hash, $i * 2, 2)) % strlen(self::ALPHABET)];
        }

        return $code;
    }

    /** Accept the current code and the previous one (for slow connections). */
    public function verify(Event $event, string $code): bool
    {
        if (! $event->check_in_secret) {
            return false;
        }

        $code = self::normalize($code);
        $window = $this->currentWindow();

        return hash_equals($this->codeFor($event, $window), $code)
            || hash_equals($this->codeFor($event, $window - 1), $code);
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** Link encoded in the QR. Opening it on a phone checks the student in. */
    public function checkInUrl(Event $event, string $code): string
    {
        return config('campus.frontend_url').'/check-in?event='.$event->id.'&code='.$code;
    }
}
