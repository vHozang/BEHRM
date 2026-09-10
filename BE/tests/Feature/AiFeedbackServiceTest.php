<?php

namespace Tests\Feature;

use App\Services\AiFeedbackService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFeedbackServiceTest extends TestCase
{
    public function test_it_prefers_mac_and_falls_back_to_windows_for_stats(): void
    {
        config([
            'services.autorecruit.mac_url' => 'http://mac-resume.test',
            'services.autorecruit.url' => 'http://windows-resume.test',
            'services.autorecruit.fallback_urls' => [],
        ]);
        Http::fake([
            'http://mac-resume.test/feedback/stats' => Http::response([], 503),
            'http://windows-resume.test/feedback/stats' => Http::response(['count' => 1]),
        ]);

        $result = (new AiFeedbackService)->getStats();

        $this->assertSame(['count' => 1], $result);
        $this->assertSame([
            'http://mac-resume.test/feedback/stats',
            'http://windows-resume.test/feedback/stats',
        ], Http::recorded()->map(fn ($recorded) => $recorded[0]->url())->all());
    }

    public function test_it_sends_structured_blind_review_for_offline_training(): void
    {
        config([
            'services.autorecruit.mac_url' => 'http://resume-backend.test',
            'services.autorecruit.url' => 'http://resume-backend.test',
            'services.autorecruit.fallback_urls' => [],
        ]);
        Http::fake([
            'http://resume-backend.test/feedback' => Http::response([
                'review_id' => 7,
                'training_eligibility' => ['eligible' => true],
            ]),
        ]);

        $result = (new AiFeedbackService)->sendFeedback(
            candidateId: 15,
            aiScore: 70,
            humanScore: 80,
            assessmentId: 9,
            reviewerId: '3',
            reviewerRole: 'HR_OR_MANAGER',
            decision: 'APPROVED',
            note: 'Đã đối chiếu CV và JD.',
            criteria: [[
                'criterion_id' => 'must_have:laravel',
                'score' => 4,
                'reason' => 'Có dự án Laravel thực tế.',
                'evidence' => 'Dự án ERP',
            ]],
            blindReview: true,
            eligibleForTraining: true,
        );

        $this->assertSame(7, $result['review_id']);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://resume-backend.test/feedback'
                && $request['assessment_id'] === 9
                && $request['blind_review'] === true
                && $request['eligible_for_training'] === true
                && $request['criteria'][0]['criterion_id'] === 'must_have:laravel';
        });
    }

    public function test_outcome_falls_back_from_mac_to_windows(): void
    {
        config([
            'services.autorecruit.mac_url' => 'http://mac-resume.test',
            'services.autorecruit.url' => 'http://mac-resume.test',
            'services.autorecruit.fallback_urls' => ['http://windows-resume.test'],
        ]);
        Http::fake([
            'http://mac-resume.test/outcomes' => Http::response([], 503),
            'http://windows-resume.test/outcomes' => Http::response(['outcome_id' => 21]),
        ]);

        $result = (new AiFeedbackService)->sendOutcome(
            candidateId: 15,
            stage: 'INTERVIEW',
            outcome: 'PASSED',
        );

        $this->assertSame(21, $result['outcome_id']);
        $this->assertSame([
            'http://mac-resume.test/outcomes',
            'http://windows-resume.test/outcomes',
        ], Http::recorded()->map(fn ($recorded) => $recorded[0]->url())->all());
    }
}
