<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DuesPayment;
use App\Models\Organization;
use App\Models\OrganizationDue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DuesController extends Controller
{
    public function pendingReviewCount(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user->is_admin || $user->organizationMemberships()->whereIn('role', ['President', 'Treasurer'])->exists(),
            403,
            'Only dues managers can view pending payment submissions.',
        );
        $organizationIds = $user->is_admin
            ? Organization::query()->select('id')
            : $user->organizationMemberships()
                ->whereIn('role', ['President', 'Treasurer'])
                ->select('organization_id');

        return response()->json([
            'data' => [
                'count' => DuesPayment::query()
                    ->where('status', 'pending')
                    ->whereHas('organizationDue', fn ($query) => $query->whereIn('organization_id', $organizationIds))
                    ->count(),
            ],
        ]);
    }

    public function mine(Request $request)
    {
        $user = $request->user();
        $organizationIds = $user->organizationMemberships()->select('organization_id');
        $dues = OrganizationDue::query()
            ->whereIn('organization_id', $organizationIds)
            ->with('organization:id,name,college,gcash_number,gcash_qr_path')
            ->orderByDesc('school_year')
            ->get();

        return response()->json([
            'data' => $dues->map(function (OrganizationDue $due) use ($user) {
                $payments = $due->payments()->where('user_id', $user->id)->latest('paid_at')->get();
                $paidCents = $this->toCents($payments->where('status', 'approved')->sum('amount'));
                $pendingCents = $this->toCents($payments->where('status', 'pending')->sum('amount'));

                return $this->memberDueData($due, $paidCents, $pendingCents, $payments);
            })->values(),
        ]);
    }

    public function saveGcashDetails(Request $request, Organization $organization)
    {
        $this->authorizeDuesManager($request, $organization);

        $validated = $request->validate([
            'gcash_number' => ['nullable', 'string', 'max:20', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'gcash_qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $gcashNumber = trim((string) ($validated['gcash_number'] ?? '')) ?: $organization->gcash_number;
        $newQrPath = null;
        if ($request->hasFile('gcash_qr')) {
            $newQrPath = $request->file('gcash_qr')->store("gcash-qr/{$organization->id}", 'public');
            abort_if(! $newQrPath, 500, 'The GCash QR image could not be saved.');
        }

        if (! $gcashNumber && ! $newQrPath && ! $organization->gcash_qr_path) {
            throw ValidationException::withMessages([
                'gcash_number' => ['Add a GCash number or upload a QR code before saving.'],
            ]);
        }

        $oldQrPath = $organization->gcash_qr_path;
        $organization->update([
            'gcash_number' => $gcashNumber,
            ...($newQrPath ? ['gcash_qr_path' => $newQrPath] : []),
        ]);
        if ($newQrPath && $oldQrPath && ! Storage::disk('public')->delete($oldQrPath)) {
            report(new \RuntimeException('The previous GCash QR image could not be removed.'));
        }

        return response()->json([
            'message' => 'GCash payment details saved.',
            'data' => [
                'gcash_number' => $organization->gcash_number,
                'gcash_qr_url' => $organization->gcash_qr_path
                    ? Storage::disk('public')->url($organization->gcash_qr_path)
                    : null,
            ],
        ]);
    }

    public function submitMemberPayment(Request $request, OrganizationDue $organizationDue)
    {
        $user = $request->user();
        $organization = $organizationDue->organization;
        abort_unless(
            $organization->members()->where('user_id', $user->id)->exists(),
            403,
            'Only organization members can submit dues payments.',
        );
        abort_unless($organization->gcash_number || $organization->gcash_qr_path, 409, 'This organization has not configured GCash payments.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reference_last_four' => ['nullable', 'digits:4'],
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        abort_unless(
            $request->hasFile('proof') || ! empty($validated['reference_last_four']),
            422,
            'Upload a proof image or enter the last four digits of the GCash reference number.',
        );

        $proofPath = null;
        try {
            $payment = DB::transaction(function () use ($request, $organizationDue, $validated, $user, &$proofPath) {
                $due = OrganizationDue::query()->lockForUpdate()->findOrFail($organizationDue->id);
                $amountCents = $this->toCents($due->amount);
                $approvedCents = $this->toCents($due->payments()->where('user_id', $user->id)->where('status', 'approved')->sum('amount'));
                $pendingCents = $this->toCents($due->payments()->where('user_id', $user->id)->where('status', 'pending')->sum('amount'));
                $paymentCents = $this->toCents($validated['amount']);

                if ($paymentCents > $amountCents - $approvedCents - $pendingCents) {
                    throw ValidationException::withMessages([
                        'amount' => ['Payment cannot exceed the remaining balance, including submissions awaiting review.'],
                    ]);
                }

                if ($request->hasFile('proof')) {
                    $proofPath = $request->file('proof')->store("dues-payment-proofs/{$due->id}/{$user->id}", 'local');
                    if (! $proofPath) {
                        abort(500, 'The payment proof could not be saved.');
                    }
                }

                return $due->payments()->create([
                    'user_id' => $user->id,
                    'amount' => number_format($paymentCents / 100, 2, '.', ''),
                    'paid_at' => now(),
                    'status' => 'pending',
                    'reference_last_four' => $validated['reference_last_four'] ?? null,
                    'proof_path' => $proofPath,
                ]);
            });
        } catch (Throwable $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Payment submitted for review.',
            'data' => ['payment_id' => $payment->id, 'status' => $payment->status],
        ], 201);
    }

    public function updateMemberPayment(Request $request, OrganizationDue $organizationDue, DuesPayment $payment)
    {
        $user = $request->user();
        abort_unless($organizationDue->organization->members()->where('user_id', $user->id)->exists(), 403);
        abort_unless($payment->organization_due_id === $organizationDue->id, 404);
        abort_unless($payment->user_id === $user->id, 403, 'You can only edit your own payment submissions.');
        abort_if($payment->status !== 'pending', 409, 'Only payment submissions awaiting review can be edited.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reference_last_four' => ['nullable', 'digits:4'],
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $newProofPath = null;
        $oldProofPath = null;
        try {
            $payment = DB::transaction(function () use ($request, $organizationDue, $payment, $validated, $user, &$newProofPath, &$oldProofPath) {
                $due = OrganizationDue::query()->lockForUpdate()->findOrFail($organizationDue->id);
                $lockedPayment = DuesPayment::query()->lockForUpdate()->findOrFail($payment->id);
                abort_unless($lockedPayment->user_id === $user->id, 403, 'You can only edit your own payment submissions.');
                abort_if($lockedPayment->status !== 'pending', 409, 'This payment submission has already been reviewed.');

                if (! $request->hasFile('proof')
                    && empty($validated['reference_last_four'])
                    && ! ($lockedPayment->proof_path && Storage::disk('local')->exists($lockedPayment->proof_path))) {
                    throw ValidationException::withMessages([
                        'proof' => ['Keep or upload a proof image, or enter the last four digits of the GCash reference number.'],
                    ]);
                }

                $approvedCents = $this->toCents($due->payments()
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->sum('amount'));
                $otherPendingCents = $this->toCents($due->payments()
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->where('id', '!=', $lockedPayment->id)
                    ->sum('amount'));
                $paymentCents = $this->toCents($validated['amount']);

                if ($paymentCents > $this->toCents($due->amount) - $approvedCents - $otherPendingCents) {
                    throw ValidationException::withMessages([
                        'amount' => ['Payment cannot exceed the remaining balance, including other submissions awaiting review.'],
                    ]);
                }

                if ($request->hasFile('proof')) {
                    $newProofPath = $request->file('proof')->store("dues-payment-proofs/{$due->id}/{$user->id}", 'local');
                    abort_if(! $newProofPath, 500, 'The payment proof could not be saved.');
                }

                $oldProofPath = $lockedPayment->proof_path;
                $lockedPayment->update([
                    'amount' => number_format($paymentCents / 100, 2, '.', ''),
                    'reference_last_four' => $validated['reference_last_four'] ?? null,
                    ...($newProofPath ? ['proof_path' => $newProofPath] : []),
                ]);

                return $lockedPayment;
            });
        } catch (Throwable $exception) {
            if ($newProofPath) {
                Storage::disk('local')->delete($newProofPath);
            }
            throw $exception;
        }

        if ($newProofPath && $oldProofPath && ! Storage::disk('local')->delete($oldProofPath)) {
            report(new \RuntimeException('The replaced payment proof could not be removed.'));
        }

        return response()->json([
            'message' => 'Payment submission updated.',
            'data' => [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'amount' => $payment->amount,
            ],
        ]);
    }

    public function reviewPayment(
        Request $request,
        Organization $organization,
        OrganizationDue $organizationDue,
        DuesPayment $payment,
    ) {
        $this->authorizeDuesManager($request, $organization);
        abort_unless($organizationDue->organization_id === $organization->id, 404);
        abort_unless($payment->organization_due_id === $organizationDue->id, 404);
        abort_unless($payment->user_id !== $request->user()->id, 403, 'You cannot review your own payment submission.');
        abort_unless($payment->recorded_by === null, 409, 'Only GCash payment submissions can be reviewed.');

        $validated = $request->validate([
            'status' => ['required', 'in:pending,approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $organizationDue, $payment, $validated) {
            $due = OrganizationDue::query()->lockForUpdate()->findOrFail($organizationDue->id);
            $lockedPayment = DuesPayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($lockedPayment->recorded_by === null, 409, 'Only GCash payment submissions can be reviewed.');
            if ($validated['status'] === 'pending') {
                abort_unless(
                    in_array($lockedPayment->status, ['approved', 'rejected'], true) && $lockedPayment->reviewed_by,
                    409,
                    'Only reviewed payment decisions can be canceled.',
                );

                $lockedPayment->update([
                    'status' => 'pending',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                ]);

                return;
            }

            abort_unless(
                in_array($lockedPayment->status, ['pending', 'approved', 'rejected'], true),
                409,
                'This payment submission cannot be reviewed.',
            );
            abort_if($lockedPayment->status !== 'pending' && ! $lockedPayment->reviewed_by, 409, 'This payment submission has no review decision to change.');

            if ($validated['status'] === 'approved' && $lockedPayment->status !== 'approved') {
                $otherApprovedCents = $due->payments()
                    ->where('user_id', $lockedPayment->user_id)
                    ->where('status', 'approved')
                    ->where('id', '!=', $lockedPayment->id)
                    ->sum('amount');
                if ($this->toCents($lockedPayment->amount) > $this->toCents($due->amount) - $this->toCents($otherApprovedCents)) {
                    throw ValidationException::withMessages([
                        'status' => ['Approving this payment would exceed the member’s remaining dues balance.'],
                    ]);
                }
            }

            $lockedPayment->update([
                'status' => $validated['status'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
            ]);
        });

        return response()->json([
            'message' => $validated['status'] === 'pending'
                ? 'Payment decision canceled. The submission is pending review again.'
                : 'Payment '.$validated['status'].'.',
            'data' => ['payment_id' => $payment->id, 'status' => $validated['status']],
        ]);
    }

    public function paymentProof(Request $request, Organization $organization, OrganizationDue $organizationDue, DuesPayment $payment)
    {
        $this->authorizeDuesManager($request, $organization);
        abort_unless($organizationDue->organization_id === $organization->id, 404);
        abort_unless($payment->organization_due_id === $organizationDue->id, 404);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }

    public function manage(Request $request, Organization $organization)
    {
        $this->authorizeDuesManager($request, $organization);

        $members = $organization->members()
            ->with('user:id,name,email,profile_picture_path')
            ->orderBy('joined_at')
            ->get();

        $dues = $organization->dues()
            ->withSum(['payments as total_collected' => fn ($query) => $query->where('status', 'approved')], 'amount')
            ->orderByDesc('school_year')
            ->get()
            ->map(function (OrganizationDue $due) use ($members) {
                $paidAmounts = $due->payments()
                    ->where('status', 'approved')
                    ->select('user_id', DB::raw('SUM(amount) as total_paid'))
                    ->groupBy('user_id')
                    ->pluck('total_paid', 'user_id');
                $paymentsByUser = $due->payments()
                    ->with(['recorder:id,name', 'reviewer:id,name'])
                    ->orderByDesc('paid_at')
                    ->get()
                    ->groupBy('user_id');
                $amountCents = $this->toCents($due->amount);

                return [
                    ...$this->dueData($due),
                    'total_collected' => number_format($this->toCents($due->total_collected ?? 0) / 100, 2, '.', ''),
                    'members' => $members->map(function ($membership) use ($due, $paidAmounts, $paymentsByUser, $amountCents) {
                        $paidCents = $this->toCents($paidAmounts->get($membership->user_id, 0));
                        $payments = $paymentsByUser->get($membership->user_id, collect());

                        return [
                            'user_id' => $membership->user_id,
                            'name' => $membership->user->name,
                            'email' => $membership->user->email,
                            'role' => $membership->role,
                            'amount' => $due->amount,
                            'paid_amount' => number_format($paidCents / 100, 2, '.', ''),
                            'balance' => number_format(max(0, $amountCents - $paidCents) / 100, 2, '.', ''),
                            'status' => $this->status($amountCents, $paidCents, $due->due_date),
                            'payments' => $payments->map(fn (DuesPayment $payment) => [
                                'id' => $payment->id,
                                'amount' => $payment->amount,
                                'paid_at' => $payment->paid_at,
                                'recorded_by' => $payment->recorder?->name,
                                'status' => $payment->status,
                                'reference_last_four' => $payment->reference_last_four,
                                'has_proof' => (bool) $payment->proof_path,
                                'reviewed_by' => $payment->reviewer?->name,
                                'reviewed_at' => $payment->reviewed_at,
                                'review_note' => $payment->review_note,
                            ])->values(),
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'college' => $organization->college,
                    'gcash_number' => $organization->gcash_number,
                    'gcash_qr_url' => $organization->gcash_qr_path
                        ? Storage::disk('public')->url($organization->gcash_qr_path)
                        : null,
                ],
                'dues' => $dues,
            ],
        ]);
    }

    public function save(Request $request, Organization $organization)
    {
        $this->authorizeDuesManager($request, $organization);

        $validated = $request->validate([
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'due_date' => ['required', 'date'],
        ]);

        $due = DB::transaction(function () use ($organization, $validated) {
            $due = $organization->dues()
                ->where('school_year', $validated['school_year'])
                ->lockForUpdate()
                ->first();

            if ($due) {
                $paidCents = $this->toCents($due->payments()->where('status', 'approved')->sum('amount'));
                abort_if(
                    $this->toCents($validated['amount']) < $paidCents,
                    409,
                    'The dues amount cannot be less than the payments already recorded.',
                );
                $due->update([
                    'amount' => $validated['amount'],
                    'due_date' => $validated['due_date'],
                ]);
            } else {
                $due = $organization->dues()->create($validated);
            }

            return $due;
        });

        return response()->json([
            'message' => 'Dues schedule saved.',
            'data' => $this->dueData($due->fresh()),
        ]);
    }

    public function recordPayment(Request $request, Organization $organization, OrganizationDue $organizationDue)
    {
        $this->authorizeDuesManager($request, $organization);
        abort_unless($organizationDue->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:organization_members,user_id',
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);
        abort_unless(
            $organization->members()->where('user_id', $validated['user_id'])->exists(),
            422,
            'Payments can only be recorded for current organization members.',
        );

        $payment = DB::transaction(function () use ($request, $organizationDue, $validated) {
            $due = OrganizationDue::query()->lockForUpdate()->findOrFail($organizationDue->id);
            $amountCents = $this->toCents($due->amount);
            $paidCents = $this->toCents($due->payments()->where('user_id', $validated['user_id'])->where('status', 'approved')->sum('amount'));
            $pendingCents = $this->toCents($due->payments()->where('user_id', $validated['user_id'])->where('status', 'pending')->sum('amount'));
            $paymentCents = $this->toCents($validated['amount']);

            if ($paymentCents > $amountCents - $paidCents - $pendingCents) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment cannot exceed the remaining balance.'],
                ]);
            }

            return $due->payments()->create([
                'user_id' => $validated['user_id'],
                'recorded_by' => $request->user()->id,
                'amount' => number_format($paymentCents / 100, 2, '.', ''),
                'paid_at' => now(),
            ]);
        });

        $payment->load([
            'user:id,name,email',
            'recorder:id,name',
            'organizationDue.organization:id,name,college',
        ]);

        return response()->json([
            'message' => 'Payment recorded.',
            'data' => $this->paymentReceiptData($payment),
        ], 201);
    }

    public function updatePayment(
        Request $request,
        Organization $organization,
        OrganizationDue $organizationDue,
        DuesPayment $payment,
    ) {
        $this->authorizeDuesManager($request, $organization);
        abort_unless($organizationDue->organization_id === $organization->id, 404);
        abort_unless($payment->organization_due_id === $organizationDue->id, 404);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        DB::transaction(function () use ($organizationDue, $payment, $validated) {
            $due = OrganizationDue::query()->lockForUpdate()->findOrFail($organizationDue->id);
            $lockedPayment = DuesPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $otherPaidCents = $due->payments()
                ->where('user_id', $lockedPayment->user_id)
                ->where('id', '!=', $lockedPayment->id)
                ->sum('amount');
            $newAmountCents = $this->toCents($validated['amount']);

            if ($newAmountCents > $this->toCents($due->amount) - $this->toCents($otherPaidCents)) {
                throw ValidationException::withMessages([
                    'amount' => ['The corrected payment cannot make total payments exceed the dues amount.'],
                ]);
            }

            $lockedPayment->update([
                'amount' => number_format($newAmountCents / 100, 2, '.', ''),
            ]);
        });

        $payment = $payment->fresh()->load([
            'user:id,name,email',
            'recorder:id,name',
            'organizationDue.organization:id,name,college',
        ]);

        return response()->json([
            'message' => 'Payment amount corrected.',
            'data' => $this->paymentReceiptData($payment),
        ]);
    }

    private function authorizeDuesManager(Request $request, Organization $organization): void
    {
        abort_unless(
            $request->user()->is_admin || $request->user()->organizationMemberships()
                ->where('organization_id', $organization->id)
                ->whereIn('role', ['President', 'Treasurer'])
                ->exists(),
            403,
            'Only the organization president or treasurer can manage dues.',
        );
    }

    private function memberDueData(OrganizationDue $due, int $paidCents, int $pendingCents, $payments): array
    {
        $dueCents = $this->toCents($due->amount);

        return [
            ...$this->dueData($due),
            'organization' => [
                'id' => $due->organization->id,
                'name' => $due->organization->name,
                'college' => $due->organization->college,
                'gcash_number' => $due->organization->gcash_number,
                'gcash_qr_url' => $due->organization->gcash_qr_path
                    ? Storage::disk('public')->url($due->organization->gcash_qr_path)
                    : null,
            ],
            'paid_amount' => number_format($paidCents / 100, 2, '.', ''),
            'pending_amount' => number_format($pendingCents / 100, 2, '.', ''),
            'balance' => number_format(max(0, $dueCents - $paidCents) / 100, 2, '.', ''),
            'status' => $this->status($dueCents, $paidCents, $due->due_date),
            'payments' => $payments->map(fn (DuesPayment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at,
                'reference_last_four' => $payment->reference_last_four,
                'has_proof' => (bool) $payment->proof_path,
                'review_note' => $payment->review_note,
            ])->values(),
        ];
    }

    private function dueData(OrganizationDue $due): array
    {
        return [
            'id' => $due->id,
            'organization_id' => $due->organization_id,
            'school_year' => $due->school_year,
            'amount' => $due->amount,
            'due_date' => $due->due_date->toDateString(),
        ];
    }

    private function paymentReceiptData(DuesPayment $payment): array
    {
        $paidCents = $this->toCents(
            $payment->organizationDue->payments()->where('user_id', $payment->user_id)->where('status', 'approved')->sum('amount'),
        );
        $dueCents = $this->toCents($payment->organizationDue->amount);

        return [
            'receipt_number' => 'DUES-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            'payment_id' => $payment->id,
            'organization' => $payment->organizationDue->organization->name,
            'college' => $payment->organizationDue->organization->college,
            'member_name' => $payment->user->name,
            'member_email' => $payment->user->email,
            'recorded_by' => $payment->recorder?->name,
            'school_year' => $payment->organizationDue->school_year,
            'due_date' => $payment->organizationDue->due_date->toDateString(),
            'dues_amount' => $payment->organizationDue->amount,
            'payment_amount' => $payment->amount,
            'status' => $payment->status,
            'total_paid' => number_format($paidCents / 100, 2, '.', ''),
            'balance' => number_format(max(0, $dueCents - $paidCents) / 100, 2, '.', ''),
            'paid_at' => $payment->paid_at,
        ];
    }

    private function status(int $dueCents, int $paidCents, $dueDate): string
    {
        if ($paidCents >= $dueCents) {
            return 'paid';
        }

        if ($dueDate->isBefore(today())) {
            return 'overdue';
        }

        return $paidCents > 0 ? 'partial' : 'unpaid';
    }

    private function toCents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
