<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class NotificationService
{
    public function send(User $user, Role $role, Workspace $workspace, string $subject, string $body, ?string $link = null): Notification
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'workspace_id' => $workspace->id,
            'subject' => $subject,
            'body' => $body,
            'link' => $link,
        ]);

        $signedUrl = $this->generateSignedUrl($notification);
        $mailable = new NotificationMail($notification, $signedUrl);

        $notification->update([
            'email_html' => $mailable->render(),
        ]);

        Mail::to($user)->queue($mailable);

        return $notification;
    }

    public function resend(Notification $notification): void
    {
        $signedUrl = $this->generateSignedUrl($notification);
        $mailable = new NotificationMail($notification, $signedUrl);

        $notification->update([
            'email_html' => $mailable->render(),
        ]);

        Mail::to($notification->user)->queue($mailable);
    }

    private function generateSignedUrl(Notification $notification): string
    {
        return URL::temporarySignedRoute(
            'notifications.redirect',
            now()->addDays(7),
            ['notification' => $notification->id],
        );
    }
}
