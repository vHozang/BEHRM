<?php

namespace App\Mail;

use App\Models\PayslipDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PayslipDocument $document) {}

    public function envelope(): Envelope
    {
        $from = new Address(
            (string) config('mail.from.address', 'hr@devtapcode.io.vn'),
            (string) config('mail.from.name', 'DevTapCode HR'),
        );

        return new Envelope(
            from: $from,
            replyTo: [$from],
            subject: 'Phiếu lương kỳ '.$this->document->period->period_code.' - '.config('app.name', 'HRM System'),
        );
    }

    public function content(): Content
    {
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            view: 'emails.payroll.payslip',
            with: [
                'employee_name' => $this->document->employee->full_name,
                'period_code' => $this->document->period->period_code,
                'portal_url' => $frontend.'/employee-portal?tab=salary&payslip='.$this->document->salary_detail_id,
                'company_name' => config('app.name', 'HRM System'),
                'company_website' => $frontend,
                'brand_logo_path' => resource_path('images/email/cdn-logo.png'),
                'brand_logo_alt' => 'DevTapCode HR',
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->document->storage_path)
                ->as($this->document->filename)
                ->withMime('application/pdf'),
        ];
    }
}
