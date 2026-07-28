<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeletionRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = DeletionRequest::with(['requestable', 'requester', 'reviewer'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(15));
    }

    public function show(DeletionRequest $deletionRequest)
    {
        return response()->json(
            $deletionRequest->load(['requestable', 'requester', 'reviewer'])
        );
    }

    public function approve(Request $request, DeletionRequest $deletionRequest)
    {
        if ($deletionRequest->status !== 'pending') {
            return response()->json(['message' => 'Este pedido já foi processado.'], 400);
        }

        return DB::transaction(function () use ($request, $deletionRequest) {
            $deletionRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $record = $deletionRequest->requestable;
            $label = class_basename($record);
            $identifier = $record->company_name ?? $record->reference_number ?? $record->title ?? "#{$record->id}";

            // Permanently remove the record
            $record->forceDelete();

            $admin = $request->user();

            AuditLog::log('Aprovação de exclusão', "Admin '{$admin->name}' aprovou a exclusão do(a) {$label} '{$identifier}'", [
                'deletion_request_id' => $deletionRequest->id,
                'requestable_type' => $deletionRequest->requestable_type,
                'requestable_id' => $deletionRequest->requestable_id,
                'reason' => $deletionRequest->reason,
                'requester_name' => $deletionRequest->requester->name,
            ], $admin);

            // Notify requester that their request was approved
            Notification::create([
                'user_id' => $deletionRequest->requested_by,
                'type' => 'deletion_approved',
                'title' => 'Exclusão Aprovada',
                'message' => "A sua solicitação de exclusão do(a) {$label} '{$identifier}' foi aprovada e o registro foi removido.",
                'data' => [
                    'deletion_request_id' => $deletionRequest->id,
                    'requestable_type' => $deletionRequest->requestable_type,
                    'requestable_id' => $deletionRequest->requestable_id,
                    'identifier' => $identifier,
                ],
            ]);

            return response()->json(['message' => 'Exclusão aprovada e registro removido com sucesso.']);
        });
    }

    public function reject(Request $request, DeletionRequest $deletionRequest)
    {
        if ($deletionRequest->status !== 'pending') {
            return response()->json(['message' => 'Este pedido já foi processado.'], 400);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated, $request, $deletionRequest) {
            $deletionRequest->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            $record = $deletionRequest->requestable;
            $label = class_basename($record);
            $identifier = $record->company_name ?? $record->reference_number ?? $record->title ?? "#{$record->id}";

            $admin = $request->user();

            AuditLog::log('Rejeição de exclusão', "Admin '{$admin->name}' rejeitou a exclusão do(a) {$label} '{$identifier}'", [
                'deletion_request_id' => $deletionRequest->id,
                'requestable_type' => $deletionRequest->requestable_type,
                'requestable_id' => $deletionRequest->requestable_id,
                'reason' => $deletionRequest->reason,
                'rejection_reason' => $validated['rejection_reason'],
                'requester_name' => $deletionRequest->requester->name,
            ], $admin);

            // Notify requester that their request was rejected
            Notification::create([
                'user_id' => $deletionRequest->requested_by,
                'type' => 'deletion_rejected',
                'title' => 'Exclusão Rejeitada',
                'message' => "A sua solicitação de exclusão do(a) {$label} '{$identifier}' foi rejeitada.\nMotivo: {$validated['rejection_reason']}",
                'data' => [
                    'deletion_request_id' => $deletionRequest->id,
                    'requestable_type' => $deletionRequest->requestable_type,
                    'requestable_id' => $deletionRequest->requestable_id,
                    'identifier' => $identifier,
                    'rejection_reason' => $validated['rejection_reason'],
                ],
            ]);

            return response()->json(['message' => 'Exclusão rejeitada. O registro foi mantido.']);
        });
    }
}
