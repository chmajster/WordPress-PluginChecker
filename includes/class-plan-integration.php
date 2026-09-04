<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Integration
{
    public function register_hooks(): void
    {
        // Punkt rozszerzenia dla WordPress SSO, mapowania użytkowników i API aplikacji Plan.
    }

    public function get_application_url(): string
    {
        return (string)get_option(Plan_Settings::OPTION_APP_URL, '');
    }
}
