<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('reports.index', [
            'from' => $from, 'to' => $to,
            'summary' => $this->reports->summary($request->user(), $from, $to),
        ]);
    }

    public function export(Request $request)
    {
        [$from, $to] = $this->range($request);
        $query = $this->reports->query($request->user(), $from, $to)->with('paymentAccount:id,label')->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order ID', 'Your order ID', 'Created', 'Paid at', 'Status', 'Amount', 'Paid amount', 'UTR', 'Account', 'Customer', 'Mobile', 'Verified by', 'Note']);
            $query->chunkById(500, function ($chunk) use ($out) {
                foreach ($chunk as $o) {
                    fputcsv($out, array_map([ReportService::class, 'csvSafe'], [
                        $o->order_no, $o->client_order_id, $o->created_at->format('Y-m-d H:i:s'), $o->paid_at?->format('Y-m-d H:i:s'),
                        $o->status, $o->amount, $o->payableAmount(), $o->utr, $o->paymentAccount?->label,
                        $o->customer_name, $o->customer_mobile, $o->verified_by, $o->note,
                    ]));
                }
            });
            fclose($out);
        }, 'payments-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(29);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
