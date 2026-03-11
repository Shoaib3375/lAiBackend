<?php
namespace App\Services;

use App\Models\User;
use Stripe\{Stripe, Customer, Subscription};

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /** Find or create a Stripe customer for this user */
    public function findOrCreateCustomer(User $user): string
    {
        if ($user->stripe_id) {
            return $user->stripe_id;
        }

        $customer = Customer::create([
            'email'    => $user->email,
            'name'     => $user->name,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->update(['stripe_id' => $customer->id]);
        return $customer->id;
    }

    /** Get subscription details */
    public function getSubscription(User $user): ?array
    {
        if (!$user->subscription_id) return null;

        $sub = Subscription::retrieve($user->subscription_id);

        return [
            'status'               => $sub->status,
            'current_period_end'   => $sub->current_period_end,
            'cancel_at_period_end' => $sub->cancel_at_period_end,
        ];
    }

    /** Cancel subscription at period end */
    public function cancelAtPeriodEnd(User $user): void
    {
        if (!$user->subscription_id) return;

        Subscription::update($user->subscription_id, [
            'cancel_at_period_end' => true,
        ]);
    }
}
