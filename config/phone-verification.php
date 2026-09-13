<?php

return [
    'driver' => env('OTP_CHANNEL', 'log'),

    'expires_in_seconds' => (int) env('OTP_EXPIRES_IN_SECONDS', 300),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'max_sends_per_hour' => (int) env('OTP_MAX_SENDS_PER_HOUR', 5),
    'max_sends_per_ip_per_hour' => (int) env('OTP_MAX_SENDS_PER_IP_PER_HOUR', 10),

    'log_channel' => env('OTP_LOG_CHANNEL', 'stack'),
];
