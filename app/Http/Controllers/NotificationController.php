<?php

namespace App\Http\Controllers;

use App\Actions\Alerts\AcknowledgeAlert;
use App\Actions\Alerts\MarkAlertRead;
use App\Actions\Alerts\MarkAllAlertsRead;
use App\Alerts\AlertFilters;
use App\Alerts\AlertInbox;
use App\Alerts\AlertSubject;
use App\Alerts\UnreadAlertCount;
use App\Models\OperationalAlertRecipient;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Operational alerts as delivered to the signed-in operator. Every route here is scoped to their own
 * recipient rows: there is no cross-user view, and no route creates, edits or deletes an alert.
 */
class NotificationController extends Controller
{
    public function index(Request $request, AlertInbox $inbox, UnreadAlertCount $unread): View
    {
        $filters = AlertFilters::fromRequest($request);

        return view('notifications.index', [
            'filters' => $filters,
            'notifications' => $inbox->paginate($request->user(), $filters, PerPage::resolve($request)),
            'unreadCount' => $unread->for($request->user()),
        ]);
    }

    public function show(Request $request, OperationalAlertRecipient $notification): View
    {
        Gate::authorize('view', $notification);

        $alert = $notification->alert()->firstOrFail();

        return view('notifications.show', [
            'notification' => $notification,
            'alert' => $alert,
            // Opening the detail page deliberately does not mark it read: a GET stays read-only and
            // read state is claimed by an explicit POST.
            'subjectUrl' => AlertSubject::url($alert, $request->user()),
        ]);
    }

    public function markRead(Request $request, OperationalAlertRecipient $notification, MarkAlertRead $action): RedirectResponse
    {
        Gate::authorize('markRead', $notification);
        $action->execute($request->user(), $notification);

        return back()->with('status', 'Notification marked as read.');
    }

    public function acknowledge(Request $request, OperationalAlertRecipient $notification, AcknowledgeAlert $action): RedirectResponse
    {
        Gate::authorize('acknowledge', $notification);
        $action->execute($request->user(), $notification);

        return back()->with('status', 'Notification acknowledged.');
    }

    public function readAll(Request $request, MarkAllAlertsRead $action): RedirectResponse
    {
        $marked = $action->execute($request->user());

        return redirect()->route('notifications.index')->with('status', $marked === 0
            ? 'No unread notifications to mark.'
            : $marked.' '.($marked === 1 ? 'notification' : 'notifications').' marked as read.');
    }
}
