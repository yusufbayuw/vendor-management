<?php

namespace App\Contracts;

interface OtpChannel
{
    public function send(string $phone, string $code): void;
}
