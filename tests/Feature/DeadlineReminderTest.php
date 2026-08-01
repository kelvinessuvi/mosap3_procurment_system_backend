<?php

namespace Tests\Feature;

use App\Mail\DeadlineReminderMail;
use App\Models\Acquisition;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeadlineReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.procurement_address' => 'procurement@mosap3.ao']);
    }

    private function createQuotationRequest(string $status = 'sent', ?Carbon $deadline = null): array
    {
        $user = User::factory()->create();
        $qr = QuotationRequest::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
            'deadline' => $deadline ? $deadline->format('Y-m-d H:i:s') : Carbon::today()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        return [$user, $qr];
    }

    private function createAcquisition(string $status = 'in_progress', ?Carbon $deliveryDate = null): array
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $qr = QuotationRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
            'deadline' => Carbon::today()->addDays(5)->format('Y-m-d H:i:s'),
        ]);

        $qs = QuotationSupplier::create([
            'quotation_request_id' => $qr->id,
            'supplier_id' => $supplier->id,
            'token' => 'token-acq-' . uniqid(),
            'status' => 'submitted',
        ]);

        $response = QuotationResponse::create([
            'quotation_supplier_id' => $qs->id,
            'delivery_date' => $deliveryDate ? $deliveryDate->format('Y-m-d') : Carbon::today()->addDays(3)->format('Y-m-d'),
            'delivery_days' => 10,
            'payment_terms' => '30 dias',
            'submitted_at' => now(),
            'status' => 'approved',
        ]);

        $acq = Acquisition::create([
            'quotation_request_id' => $qr->id,
            'quotation_response_id' => $response->id,
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'reference_number' => 'ACQ-' . strtoupper(uniqid()),
            'total_amount' => 1000.00,
            'status' => $status,
            'expected_delivery_date' => $deliveryDate ? $deliveryDate->format('Y-m-d') : Carbon::today()->addDays(2)->format('Y-m-d'),
        ]);

        return [$user, $acq];
    }

    public function test_quotation_t_minus_2_creates_in_app_notification()
    {
        [$user, $qr] = $this->createQuotationRequest('sent', Carbon::today()->addDays(2));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'deadline_reminder',
        ]);

        $notification = Notification::first();
        $this->assertEquals('t_minus_2', $notification->data['reminder_type']);
        $this->assertEquals($qr->id, $notification->data['quotation_request_id']);
    }

    public function test_quotation_t_minus_1_creates_notification()
    {
        [$user] = $this->createQuotationRequest('sent', Carbon::today()->addDay());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals('t_minus_1', $notification->data['reminder_type']);
    }

    public function test_quotation_due_date_creates_notification()
    {
        [$user] = $this->createQuotationRequest('sent', Carbon::today());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals('due_date', $notification->data['reminder_type']);
    }

    public function test_quotation_overdue_creates_notification()
    {
        [$user] = $this->createQuotationRequest('sent', Carbon::yesterday());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals('overdue', $notification->data['reminder_type']);
        $this->assertStringContainsString('EXCEDIDO há 1 dia', $notification->message);
    }

    public function test_completed_or_cancelled_quotations_are_skipped()
    {
        $this->createQuotationRequest('completed', Carbon::today());
        $this->createQuotationRequest('cancelled', Carbon::today());
        $this->createQuotationRequest('draft', Carbon::today());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $this->assertEquals(0, Notification::count());
        $this->assertEquals(0, \App\Models\ReminderLog::count());
    }

    public function test_quotation_reminder_sends_emails_to_procurement_user_and_pending_suppliers()
    {
        Mail::fake();
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create(['email' => 'fornecedor@test.com']);
        $supplier2 = Supplier::factory()->create(['email' => 'fornecedor2@test.com']);

        $qr = QuotationRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'sent',
            'deadline' => Carbon::today()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        // pending supplier -> should receive email
        $qr->suppliers()->attach($supplier->id, ['token' => 'token-pendente-1', 'status' => 'sent']);
        // already submitted supplier -> should NOT receive email
        $qr->suppliers()->attach($supplier2->id, ['token' => 'token-submetido-2', 'status' => 'submitted']);

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        Mail::assertSent(DeadlineReminderMail::class, function ($mail) use ($supplier) {
            return $mail->hasTo($supplier->email)
                && $mail->token === 'token-pendente-1'
                && $mail->recipientKind === 'supplier';
        });

        Mail::assertSent(DeadlineReminderMail::class, fn ($mail) => $mail->hasTo('procurement@mosap3.ao'));
        Mail::assertSent(DeadlineReminderMail::class, fn ($mail) => $mail->hasTo($user->email));

        Mail::assertNotSent(DeadlineReminderMail::class, fn ($mail) => $mail->hasTo('fornecedor2@test.com'));
    }

    public function test_command_does_not_duplicate_reminders_same_day()
    {
        $this->createQuotationRequest('sent', Carbon::today());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();
        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $this->assertEquals(1, Notification::count());
        $this->assertEquals(1, \App\Models\ReminderLog::where('channel', 'in_app')->count());
    }

    public function test_overdue_reminder_refires_next_day()
    {
        $this->createQuotationRequest('sent', Carbon::yesterday());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();
        $this->assertEquals(1, Notification::count());

        Carbon::setTestNow(Carbon::tomorrow()->startOfDay());
        $this->artisan('app:send-deadline-reminders')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertEquals(2, Notification::count());
        $this->assertEquals(2, \App\Models\ReminderLog::where('channel', 'in_app')->count());
    }

    public function test_acquisition_delivery_reminder_creates_notification()
    {
        [$user, $acq] = $this->createAcquisition('in_progress', Carbon::today()->addDays(2));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals('t_minus_2', $notification->data['reminder_type']);
        $this->assertEquals($acq->id, $notification->data['acquisition_id']);
    }

    public function test_acquisition_overdue_creates_notification()
    {
        [$user] = $this->createAcquisition('in_progress', Carbon::yesterday());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals('overdue', $notification->data['reminder_type']);
        $this->assertStringContainsString('ATRASADA há 1 dia', $notification->message);
    }

    public function test_completed_acquisitions_are_skipped()
    {
        $this->createAcquisition('completed', Carbon::today());
        $this->createAcquisition('cancelled', Carbon::today());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $this->assertEquals(0, Notification::count());
    }

    public function test_acquisition_reminder_sends_emails_to_procurement_and_user()
    {
        Mail::fake();
        [$user] = $this->createAcquisition('in_progress', Carbon::today()->addDays(2));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        Mail::assertSent(DeadlineReminderMail::class, fn ($mail) => $mail->hasTo('procurement@mosap3.ao'));
        Mail::assertSent(DeadlineReminderMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_acquisition_reminder_sends_delivery_email_to_supplier_even_when_pivot_submitted()
    {
        Mail::fake();
        [$user, $acq] = $this->createAcquisition('in_progress', Carbon::today()->addDays(2));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $supplier = $acq->supplier;
        Mail::assertSent(DeadlineReminderMail::class, function ($mail) use ($supplier) {
            return $mail->hasTo($supplier->email)
                && $mail->recipientKind === 'supplier'
                && $mail->entityType === 'delivery'
                && $mail->recipientName === $supplier->company_name
                && $mail->token === null;
        });
    }

    public function test_acquisition_overdue_reminder_sends_delivery_email_to_supplier()
    {
        Mail::fake();
        [$user, $acq] = $this->createAcquisition('in_progress', Carbon::yesterday());

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        Mail::assertSent(DeadlineReminderMail::class, function ($mail) use ($acq) {
            return $mail->hasTo($acq->supplier->email)
                && $mail->recipientKind === 'supplier'
                && $mail->trigger === 'overdue';
        });
    }

    public function test_acquisition_notification_includes_activity_title()
    {
        [$user, $acq] = $this->createAcquisition('in_progress', Carbon::today()->addDays(2));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals($acq->quotationRequest->title, $notification->data['title']);
        $this->assertStringContainsString($acq->quotationRequest->title, $notification->message);
    }

    public function test_quotation_notification_includes_activity_title()
    {
        [$user, $qr] = $this->createQuotationRequest('sent', Carbon::today()->addDays(2));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $notification = Notification::first();
        $this->assertEquals($qr->title, $notification->data['title']);
    }

    public function test_far_future_deadlines_are_not_reminded()
    {
        $this->createQuotationRequest('sent', Carbon::today()->addDays(5));

        $this->artisan('app:send-deadline-reminders')->assertSuccessful();

        $this->assertEquals(0, Notification::count());
    }
}
