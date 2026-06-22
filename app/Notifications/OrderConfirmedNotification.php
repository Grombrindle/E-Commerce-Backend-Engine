<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order Confirmed — {$this->order->order_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your order #{$this->order->order_number} has been confirmed.")
            ->line("Total: \$" . number_format($this->order->total, 2))
            ->action('View Order', url("/orders/{$this->order->id}"))
            ->line('Thank you for shopping with us!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'total'        => $this->order->total,
            'status'       => $this->order->status,
        ];
    }
}
