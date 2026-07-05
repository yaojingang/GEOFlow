<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $query = $this->filteredQuery($request);
        $submissions = (clone $query)
            ->with(['form:id,name,slug', 'handler:id,username'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.leads.index', [
            'pageTitle' => __('admin.leads.page_title'),
            'activeMenu' => 'analytics',
            'adminSiteName' => AdminWeb::siteName(),
            'submissions' => $submissions,
            'forms' => LeadForm::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['status', 'form_id', 'date_from', 'date_to', 'search']),
            'stats' => [
                'total' => LeadSubmission::query()->count(),
                'new' => LeadSubmission::query()->where('status', LeadSubmission::STATUS_NEW)->count(),
                'pending' => LeadSubmission::query()->whereIn('status', [LeadSubmission::STATUS_NEW, LeadSubmission::STATUS_CONTACTED])->count(),
                'converted' => LeadSubmission::query()->where('status', LeadSubmission::STATUS_CONVERTED)->count(),
            ],
        ]);
    }

    public function show(int $submissionId): View
    {
        $submission = LeadSubmission::query()
            ->with(['form:id,name,slug,fields', 'handler:id,username'])
            ->whereKey($submissionId)
            ->firstOrFail();

        return view('admin.leads.show', [
            'pageTitle' => __('admin.leads.detail_title'),
            'activeMenu' => 'analytics',
            'adminSiteName' => AdminWeb::siteName(),
            'submission' => $submission,
        ]);
    }

    public function update(int $submissionId, Request $request): RedirectResponse
    {
        $submission = LeadSubmission::query()->whereKey($submissionId)->firstOrFail();
        $payload = $request->validate([
            'status' => ['required', Rule::in(LeadSubmission::STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission->update([
            'status' => (string) $payload['status'],
            'note' => trim((string) ($payload['note'] ?? '')),
            'handled_by' => (int) (auth('admin')->id() ?? 0),
            'handled_at' => now(),
        ]);

        return redirect()->route('admin.leads.show', ['submissionId' => $submission->id])->with('message', __('admin.leads.message.updated'));
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'geoflow-leads-'.now()->format('Ymd-His').'.csv';
        $query = $this->filteredQuery($request);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                __('admin.leads.export.id'),
                __('admin.leads.export.form'),
                __('admin.leads.export.status'),
                __('admin.leads.export.payload'),
                __('admin.leads.export.source_url'),
                __('admin.leads.export.note'),
                __('admin.leads.export.created_at'),
            ]);

            $query->chunkById(200, function ($rows) use ($handle): void {
                $rows->load('form:id,name');

                foreach ($rows as $row) {
                    if (! $row instanceof LeadSubmission) {
                        continue;
                    }

                    fputcsv($handle, [
                        $row->id,
                        $this->csvCell($row->form?->name ?? ''),
                        $this->csvCell(__('admin.leads.status.'.$row->status)),
                        $this->csvCell(json_encode($row->payload ?? [], JSON_UNESCAPED_UNICODE)),
                        $this->csvCell($row->source_url ?? ''),
                        $this->csvCell($row->note ?? ''),
                        $this->csvCell($row->created_at?->format('Y-m-d H:i:s') ?? ''),
                    ]);
                }
            });
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Builder<LeadSubmission>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = LeadSubmission::query();

        $status = trim((string) $request->query('status', ''));
        if (in_array($status, LeadSubmission::STATUSES, true)) {
            $query->where('status', $status);
        }

        $formId = (int) $request->query('form_id', 0);
        if ($formId > 0) {
            $query->where('lead_form_id', $formId);
        }

        $dateFrom = $this->dateFilter($request->query('date_from'));
        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = $this->dateFilter($request->query('date_to'));
        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $payloadExpression = $this->payloadSearchExpression();
            $query->where(function (Builder $inner) use ($like, $payloadExpression): void {
                $inner->whereRaw('LOWER(COALESCE(source_url, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw($payloadExpression.' LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(note, ?)) LIKE ?', ['', $like]);
            });
        }

        return $query;
    }

    private function csvCell(mixed $value): string
    {
        $cell = is_string($value) ? $value : (string) $value;

        if ($cell !== '' && preg_match('/^[=+\-@\t\r]/', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }

    private function dateFilter(mixed $value): ?string
    {
        $date = trim((string) $value);
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function payloadSearchExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'LOWER(CAST(payload AS CHAR))',
            'sqlsrv' => 'LOWER(CAST(payload AS NVARCHAR(MAX)))',
            default => 'LOWER(CAST(payload AS TEXT))',
        };
    }
}
