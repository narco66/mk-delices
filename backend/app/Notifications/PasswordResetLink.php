<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email « mot de passe oublié » : lien vers la page du site, valable une heure, utilisable une seule fois. */
class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return rtrim((string) config('mkdelices.frontend_url'), '/').'/mot-de-passe/nouveau?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('MK Délices — Réinitialisation de votre mot de passe')
            ->greeting('Bonjour,')
            ->line('Vous avez demandé à changer le mot de passe de votre compte MK Délices.')
            ->action('Choisir un nouveau mot de passe', $this->url($notifiable))
            ->line("Ce lien est valable {$minutes} minutes et ne peut servir qu'une fois.")
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email : votre mot de passe reste inchangé.')
            ->salutation('L\'équipe MK Délices');
    }
}
