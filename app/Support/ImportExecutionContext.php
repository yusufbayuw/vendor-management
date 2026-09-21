<?php

namespace App\Support;

final class ImportExecutionContext
{
    private static int $notificationSuppressionDepth = 0;

    public static function suppressesNotifications(): bool
    {
        return self::$notificationSuppressionDepth > 0;
    }

    public static function withoutWorkflowNotifications(callable $callback): mixed
    {
        self::$notificationSuppressionDepth++;

        try {
            return $callback();
        } finally {
            self::$notificationSuppressionDepth--;
        }
    }
}
