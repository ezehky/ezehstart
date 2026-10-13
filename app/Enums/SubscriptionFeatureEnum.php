<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What a plan can give. Code asks about a case, never about a plan name:
 *
 *     app(SubscriptionService::class)->allows($user, SubscriptionFeatureEnum::API_ACCESS)
 *     app(SubscriptionService::class)->limitFor($user, SubscriptionFeatureEnum::TEAM_MEMBERS)
 *
 * The same rows draw the checklist on the pricing cards, so a plan can never be
 * advertised with something the code does not grant.
 *
 * The cases below are examples — a starter kit has nothing of its own to sell.
 * Replace them with the project's. Remove a case only after its
 * `subscription_plan_features` rows are gone: a stored value with no case
 * cannot be read back.
 */
enum SubscriptionFeatureEnum: string
{
    use WithEnumHelpers;

    // On or off.
    case PRIORITY_SUPPORT = 'priority-support';
    case API_ACCESS = 'api-access';

    // Up to a number, or unlimited.
    case TEAM_MEMBERS = 'team-members';
    case PROJECTS = 'projects';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::PRIORITY_SUPPORT => 'Priority support',
            self::API_ACCESS => 'API access',
            self::TEAM_MEMBERS => 'Team members',
            self::PROJECTS => 'Projects',
        };

        return $lowercase ? strtolower($label) : $label;
    }

    /**
     * A limit carries a number on the plan; anything else is simply granted or
     * not. Every case that counts something belongs here.
     */
    public function isLimit(): bool
    {
        return match ($this) {
            self::TEAM_MEMBERS, self::PROJECTS => true,
            default => false,
        };
    }

    /**
     * The line on a pricing card. A null limit is unlimited.
     */
    public function displayLine(?int $limit = null): string
    {
        if (! $this->isLimit()) {
            return $this->label();
        }

        return $limit === null
            ? 'Unlimited '.$this->label(true)
            : 'Up to '.number_format($limit).' '.$this->label(true);
    }
}
