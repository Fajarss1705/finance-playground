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
    private const LINK_EXPIRY_DAYS = 7;

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

        $mailable = $this->buildMailable($notification);

        $notification->update([
            'email_html' => $mailable->render(),
        ]);

        Mail::to($user)->queue($mailable);

        return $notification;
    }

    public function resend(Notification $notification): void
    {
        $mailable = $this->buildMailable($notification);

        $notification->update([
            'email_html' => $mailable->render(),
        ]);

        Mail::to($notification->user)->queue($mailable);
    }

    private function buildMailable(Notification $notification): NotificationMail
    {
        $expiresAt = now()->addDays(self::LINK_EXPIRY_DAYS);

        $signedUrl = URL::temporarySignedRoute(
            'notifications.redirect',
            $expiresAt,
            ['notification' => $notification->id],
        );

        return new NotificationMail(
            $notification,
            $signedUrl,
            $expiresAt->translatedFormat('d F Y, H:i').' WIB',
        );
    }
}
