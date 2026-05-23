<?php

function billing_plans(): array {
    return [
        'free' => [
            'name' => 'Free',
            'price_paise' => 0,
            'faq_limit' => 25,
            'features' => [
                'email_otp' => false,
                'mobile_otp' => false,
                'whatsapp_redirect' => false,
                'human_handoff' => false,
                'faq_action_suggestions' => false,
                'export_reports' => false,
                'partial_analytics' => false,
                'advanced_analytics' => false
            ]
        ],
        'starter' => [
            'name' => 'Starter',
            'price_paise' => 19900,
            'faq_limit' => 100,
            'features' => [
                'email_otp' => true,
                'mobile_otp' => true,
                'whatsapp_redirect' => true,
                'webhook_support' => true,
                'human_handoff' => false,
                'faq_action_suggestions' => false,
                'export_reports' => false,
                'partial_analytics' => false,
                'advanced_analytics' => false
            ]
        ],
        'growth' => [
            'name' => 'Growth',
            'price_paise' => 49900,
            'faq_limit' => 300,
            'features' => [
                'email_otp' => true,
                'mobile_otp' => true,
                'whatsapp_redirect' => true,
                'webhook_support' => true,
                'human_handoff' => true,
                'faq_action_suggestions' => true,
                'export_reports' => false,
                'partial_analytics' => true,
                'advanced_analytics' => false
            ]
        ],
        'business' => [
            'name' => 'Business',
            'price_paise' => 99900,
            'faq_limit' => PHP_INT_MAX,
            'features' => [
                'email_otp' => true,
                'mobile_otp' => true,
                'whatsapp_redirect' => true,
                'export_reports' => true,
                'partial_analytics' => true,
                'advanced_analytics' => true,
                'api_access' => true,
                'webhook_support' => true,
                'human_handoff' => true,
                'faq_action_suggestions' => true,
                'combined_widget' => true,
                'allowed_domains' => true,
                'live_chat_actions' => true
            ]
        ]
    ];
}

function billing_plan(string $planId): array {
    $plans = billing_plans();
    return $plans[$planId] ?? $plans['free'];
}

function billing_plan_ids(): array {
    return array_keys(billing_plans());
}

function billing_feature_enabled(string $planId, string $feature): bool {
    $plan = billing_plan($planId);
    return !empty($plan['features'][$feature]);
}

function billing_faq_limit(string $planId): int {
    return (int)(billing_plan($planId)['faq_limit'] ?? 25);
}

function billing_auto_recharge_rule(string $planId): array {
    $rules = [
        'starter' => [
            'threshold_paise' => 5000,
            'amount_paise' => 19900
        ],
        'growth' => [
            'threshold_paise' => 10000,
            'amount_paise' => 49900
        ],
        'business' => [
            'threshold_paise' => 20000,
            'amount_paise' => 99900
        ]
    ];
    return $rules[$planId] ?? [
        'threshold_paise' => 0,
        'amount_paise' => 0
    ];
}

function billing_active_plan_from_account(array $account): string {
    $planId = (string)($account['current_plan'] ?? 'free');
    if ($planId === 'automation' || $planId === 'enterprise') {
        $planId = 'business';
    }
    $status = (string)($account['subscription_status'] ?? 'free');
    $walletBalance = (int)($account['wallet_balance_paise'] ?? 0);
    $periodEnd = (string)($account['current_period_end'] ?? '');
    if ($planId === 'free') {
        return 'free';
    }
    if ($status === 'cancelled') {
        return $walletBalance > 0 && in_array($planId, billing_plan_ids(), true) ? $planId : 'free';
    }
    if ($status !== 'active') {
        return 'free';
    }
    if ($periodEnd !== '' && strtotime($periodEnd) < time()) {
        return 'free';
    }
    return in_array($planId, billing_plan_ids(), true) ? $planId : 'free';
}

function billing_plan_is_free_effective(array $account): bool {
    return billing_active_plan_from_account($account) === 'free';
}

function billing_rupees(int $paise): string {
    return '₹' . rtrim(rtrim(number_format($paise / 100, 2, '.', ''), '0'), '.');
}
function billing_wallet_charge_paise(string $planId, string $chargeKey): int {
    $charges = [
        'starter' => [
            'fresh_email_lead' => 600,
            'repeat_email_lead' => 200,
            'reactivated_email_lead' => 600,
            'fresh_mobile_lead' => 1200,
            'repeat_mobile_lead' => 300,
            'reactivated_mobile_lead' => 1200,
            'whatsapp_redirect_addon' => 9900
        ],
        'growth' => [
            'fresh_email_lead' => 500,
            'repeat_email_lead' => 100,
            'reactivated_email_lead' => 500,
            'fresh_mobile_lead' => 1000,
            'repeat_mobile_lead' => 200,
            'reactivated_mobile_lead' => 1000,
            'whatsapp_redirect_addon' => 9900
        ],
        'business' => [
            'fresh_email_lead' => 500,
            'repeat_email_lead' => 100,
            'reactivated_email_lead' => 500,
            'fresh_mobile_lead' => 1000,
            'repeat_mobile_lead' => 200,
            'reactivated_mobile_lead' => 1000,
            'repeat_lead' => 100,
            'fresh_combined_lead' => 1500,
            'repeat_combined_lead' => 300,
            'reactivated_combined_lead' => 1500,
            'whatsapp_redirect_addon' => 9900
        ]
    ];
    return (int)($charges[$planId][$chargeKey] ?? 0);
}
?>
