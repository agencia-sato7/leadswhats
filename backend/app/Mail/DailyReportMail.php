<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\DailyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly DailyReport $report,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Relatório diário '.$this->report->report_date?->format('d/m/Y').' — '.$this->company->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily-report',
            with: [
                'companyName' => $this->company->name,
                'reportDate' => $this->report->report_date?->format('d/m/Y'),
                'metrics' => $this->report->metrics ?? [],
                'quality' => $this->report->quality ?? [],
                'aiReport' => $this->report->report_payload ?? [],
            ],
        );
    }
}
