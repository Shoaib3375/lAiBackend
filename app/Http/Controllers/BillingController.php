<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Webhook;

class BillingController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /** POST /api/billing/checkout — create Stripe checkout */
    public function checkout(Request $request)
    {
        $request->validate(['plan' => 'required|in:pro,team']);
        $user = auth()->user();

        $priceId = $request->plan === 'pro'
            ? config('services.stripe.price_pro')
            : config('services.stripe.price_team');

        $session = Session::create([
            'customer_email'       => $user->email,
            'client_reference_id'  => $user->id,
            'mode'                 => 'subscription',
            'line_items'           => [['price' => $priceId, 'quantity' => 1]],
            'success_url'          => env('FRONTEND_URL') . '/billing/success',
            'cancel_url'           => env('FRONTEND_URL') . '/billing',
        ]);

        return response()->json(['url' => $session->url]);
    }

    /** GET /api/billing/portal — redirect to Stripe portal */
    public function portal()
    {
        $user = auth()->user();
        $portal = PortalSession::create([
            'customer'   => $user->stripe_id,
            'return_url' => env('FRONTEND_URL') . '/settings',
        ]);
        return response()->json(['url' => $portal->url]);
    }

    /** POST /webhook/stripe — handle Stripe events */
    public function stripeWebhook(Request $request)
    {
        $event = Webhook::constructEvent(
            $request->getContent(),
            $request->header('Stripe-Signature'),
            config('services.stripe.webhook_secret')
        );

        match ($event->type) {
            'checkout.session.completed'    => $this->handleCheckoutComplete($event),
            'customer.subscription.deleted' => $this->handleSubscriptionCancel($event),
            default => null
        };

        return response()->json(['received' => true]);
    }

    private function handleCheckoutComplete($event): void
    {
        $session = $event->data->object;
        \App\Models\User::find($session->client_reference_id)?->update([
            'stripe_id'        => $session->customer,
            'plan'             => 'pro', // derive from price ID in production
            'subscription_id'  => $session->subscription,
        ]);
    }

    private function handleSubscriptionCancel($event): void
    {
        \App\Models\User::where('stripe_id', $event->data->object->customer)
            ->update(['plan' => 'free']);
    }
}
