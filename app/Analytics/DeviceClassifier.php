<?php

namespace App\Analytics;

class DeviceClassifier
{
    public const DEVICES = ['iphone', 'android-phone', 'mac', 'windows', 'ipad', 'android-tablet'];

    public function bot(string $agent): bool
    {
        return $agent === '' || preg_match('/bot|crawler|spider|slurp|headless|monitor|uptime|lighthouse|pagespeed|curl|wget|python-requests/i', $agent) === 1;
    }

    public function classify(string $agent): string
    {
        if (preg_match('/iPad/i', $agent)) {
            return 'ipad';
        }
        if (preg_match('/Android/i', $agent)) {
            return preg_match('/Mobile/i', $agent) ? 'android-phone' : 'android-tablet';
        }
        if (preg_match('/iPhone/i', $agent)) {
            return 'iphone';
        }
        if (preg_match('/Macintosh/i', $agent) && ! preg_match('/Mobile/i', $agent)) {
            return 'mac';
        }
        if (preg_match('/Windows NT/i', $agent) && ! preg_match('/Phone|Mobile/i', $agent)) {
            return 'windows';
        }

        return 'other';
    }
}
