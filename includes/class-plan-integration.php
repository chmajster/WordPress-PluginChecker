<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Integration
{
    public function register_hooks(): void
    {
        // Punkt rozszerzenia dla właściwego flow SSO/API po zdefiniowaniu kontraktu endpointów aplikacji Plan.
    }

    public function get_application_url(): string
    {
        return (string)get_option(Plan_Settings::OPTION_APP_URL, '');
    }

    public function get_role_map(): array
    {
        $map = get_option(Plan_Settings::OPTION_ROLE_MAP, []);
        return is_array($map) ? $map : [];
    }

    public function get_mapped_plan_role(WP_User $user): ?string
    {
        $map = $this->get_role_map();
        foreach ((array)$user->roles as $role) {
            $mapped = $map[$role] ?? 'none';
            if (is_string($mapped) && $mapped !== '' && $mapped !== 'none') {
                return $mapped;
            }
        }
        return null;
    }
}
