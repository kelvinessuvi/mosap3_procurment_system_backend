<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckDeliveryDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-delivery-dates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for upcoming deliveries and notify users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = \Carbon\Carbon::today();
        $tomorrow = \Carbon\Carbon::tomorrow();
        $threeDays = \Carbon\Carbon::today()->addDays(3);

        $acquisitions = \App\Models\Acquisition::whereIn('status', ['pending', 'in_progress'])
            ->where(function ($query) use ($today, $tomorrow, $threeDays) {
                $query->whereDate('expected_delivery_date', $today)
                      ->orWhereDate('expected_delivery_date', $tomorrow)
                      ->orWhereDate('expected_delivery_date', $threeDays);
            })
            ->with(['supplier', 'user']) // Eager load relationships
            ->get();

        $count = 0;

        foreach ($acquisitions as $acquisition) {
            $deliveryDate = \Carbon\Carbon::parse($acquisition->expected_delivery_date)->startOfDay();
            $daysDiff = $today->diffInDays($deliveryDate, false); // false = return negative if past
            
            // Just double check logic since query is broad
            if ($daysDiff < 0) continue; // Skip past dates if logic allows

            $message = '';
            $title = '';
            $type = 'delivery_reminder';

            if ($deliveryDate->isToday()) {
                $title = 'Entrega Hoje!';
                $message = "A entrega do pedido #{$acquisition->reference_number} pelo fornecedor {$acquisition->supplier->commercial_name} está prevista para HOJE.";
            } elseif ($deliveryDate->isTomorrow()) {
                $title = 'Entrega Amanhã';
                $message = "A entrega do pedido #{$acquisition->reference_number} está prevista para AMANHÃ.";
            } elseif ($daysDiff == 3) {
                $title = 'Entrega em Breve';
                $message = "Faltam 3 dias para a entrega do pedido #{$acquisition->reference_number}.";
            } else {
                continue;
            }

            // check duplicate notification for today to avoid spam if run multiple times?
            // Simple check: existing notification with same type, title and date created today
            $exists = \App\Models\Notification::where('user_id', $acquisition->user_id)
                ->where('type', $type)
                ->where('title', $title)
                ->where('created_at', '>=', \Carbon\Carbon::today())
                ->exists();

            if (!$exists) {
                \App\Models\Notification::create([
                    'user_id' => $acquisition->user_id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => [
                        'acquisition_id' => $acquisition->id,
                        'reference_number' => $acquisition->reference_number,
                        'supplier_name' => $acquisition->supplier->commercial_name,
                        'expected_delivery_date' => $acquisition->expected_delivery_date->format('Y-m-d')
                    ]
                ]);
                $count++;
            }
        }

        $this->info("Notifications sent: {$count}");
    }
}
