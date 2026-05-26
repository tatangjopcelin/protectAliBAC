<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ProductCreatedNotification extends Notification
{
    use Queueable;

    protected $product;
    protected $creator;

    /**
     * Create a new notification instance.
     */
    public function __construct(Product $product, User $creator)
    {
        $this->product = $product;
        $this->creator = $creator;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zoneName = $this->product->zone ? $this->product->zone->name : 'Non spécifiée';
        $categoryName = $this->product->category ? $this->product->category->name : 'Non spécifiée';

        $mail = (new MailMessage)
            ->subject('Nouveau produit créé - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->creator->name . ' a créé un nouveau produit.')
            ->line('**Détails du produit :**')
            ->line('• Nom : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Quantité : ' . $this->product->quantity . ' ' . $this->product->unit)
            ->line('• Date de réception : ' . \Carbon\Carbon::parse($this->product->reception_date)->format('d/m/Y'))
            ->line('• Date d\'expiration : ' . \Carbon\Carbon::parse($this->product->expiration_date)->format('d/m/Y'))
            ->line('Créé le : ' . $this->product->created_at->format('d/m/Y à H:i'));

        // Ajouter l'image du produit si disponible
        if (!empty($this->product->photo)) {
            $photo = $this->product->photo;

            if (Str::startsWith($photo, ['http://', 'https://', 'data:image'])) {
                $photoUrl = $photo;
            } else {
                $baseUrl = config('app.url', '');
                $photoUrl = rtrim($baseUrl, '/') . '/' . ltrim($photo, '/');
            }

            $mail->line(new HtmlString(
                '<p style="margin-top:12px;margin-bottom:12px;">'
                . 'Photo du produit :'
                . '</p>'
                . '<p><img src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" alt="'
                . htmlspecialchars($this->product->name, ENT_QUOTES, 'UTF-8')
                . '" style="max-width:300px;border-radius:4px;" /></p>'
            ));
        }

        $mail->salutation('Cordialement, L\'équipe ' . $notifiable->getMailSignatureName());

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'creator_id' => $this->creator->id,
            'creator_name' => $this->creator->name,
        ];
    }
}
