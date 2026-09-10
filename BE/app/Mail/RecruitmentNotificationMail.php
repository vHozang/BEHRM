<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class RecruitmentNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public const APPLICATION_RECEIVED = 'application-received';

    public const INTERVIEW_INVITATION = 'interview-invitation';

    public const HIRED = 'hired';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $notificationType,
        public readonly array $mailData,
    ) {
        if (! array_key_exists($notificationType, self::definitions())) {
            throw new InvalidArgumentException("Unsupported recruitment notification: {$notificationType}");
        }
    }

    public function envelope(): Envelope
    {
        $definition = self::definitions()[$this->notificationType];
        $subject = strtr($definition['subject'], [
            ':position' => $this->mailData['position_name'],
            ':company' => $this->mailData['company_name'],
        ]);

        $from = new Address(
            (string) config('recruitment.mail.from_address', 'hr@devtapcode.io.vn'),
            (string) config('recruitment.mail.from_name', 'DEVTAPCODE HR'),
        );

        return new Envelope(
            from: $from,
            replyTo: [$from],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: self::definitions()[$this->notificationType]['view'],
            with: array_merge($this->mailData, [
                'brand_logo_path' => (string) config(
                    'recruitment.mail.logo_path',
                    resource_path('images/email/cdn-logo.png'),
                ),
                'brand_logo_alt' => (string) config('recruitment.mail.logo_alt', 'CDN HR'),
            ]),
        );
    }

    /** @return array<string, array{subject:string, view:string}> */
    private static function definitions(): array
    {
        return [
            self::APPLICATION_RECEIVED => [
                'subject' => 'Đã nhận hồ sơ ứng tuyển vị trí :position - :company',
                'view' => 'emails.recruitment.application-received',
            ],
            self::INTERVIEW_INVITATION => [
                'subject' => 'Thư mời phỏng vấn vị trí :position - :company',
                'view' => 'emails.recruitment.interview-invitation',
            ],
            self::HIRED => [
                'subject' => 'Thư mời nhận việc vị trí :position - :company',
                'view' => 'emails.recruitment.hired',
            ],
            self::REJECTED => [
                'subject' => 'Kết quả ứng tuyển vị trí :position - :company',
                'view' => 'emails.recruitment.rejected',
            ],
        ];
    }
}
