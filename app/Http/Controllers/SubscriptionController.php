<?php

namespace App\Http\Controllers;

use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Subscriptions\SubscriptionAccess;
use App\Tenancy\CurrentBusiness;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Administrator's view of their own Business's plan and subscription. Read-only: with no
 * payment provider yet, no tenant can change their plan or state from here or anywhere else.
 */
class SubscriptionController extends Controller
{
    public function __invoke(Request $request, CurrentBusiness $tenancy, SubscriptionAccess $access, Entitlements $entitlements): View
    {
        $business = $tenancy->forActor($request->user());
        $subscription = $access->subscription($business);

        return view('subscription.show', [
            'subscription' => $subscription,
            'plan' => $subscription->plan,
            'status' => $access->status($business),
            'access' => $access->for($business),
            // Every allowance with its usage, from this Business's own tables.
            'allowances' => collect([
                'Managers' => Entitlement::MaxManagers,
                'Sales Representatives' => Entitlement::MaxSalesRepresentatives,
                'Products' => Entitlement::MaxProducts,
            ])->map(fn (Entitlement $limit): array => [
                'used' => $entitlements->inUse($business, $limit),
                'limit' => $entitlements->limit($business, $limit),
            ]),
            'whatsapp' => $subscription->plan->grant(Entitlement::WhatsAppAutomation),
        ]);
    }
}
