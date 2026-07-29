<?php

namespace Tests\Feature;

use App\Services\AiFeedbackService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFeedbackServiceTest extends TestCase
{
    public function test_it_uses_the_configured_autorecruit_url(): void
    {
        config(['services.autorecruit.url' => 'http://100.95.129.101:8000/']);
        Http::fake([
            'http://100.95.129.101:8000/feedback/stats' => Http::response(['count' => 1]),
        ]);

        $result = (new AiFeedbackService())->getStats();

        $this->assertSame(['count' => 1], $result);
        Http::assertSent(fn ($request) => $request->url() === 'http://100.95.129.101:8000/feedback/stats');
    }
}
