<?php

namespace Tests\Feature;

use App\Tutorials\TutorialProviders;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TutorialProvidersTest extends TestCase
{
    public function test_all_documented_url_variants_preserve_case_and_canonicalize_without_network_requests(): void
    {
        $parser = app(TutorialProviders::class);
        foreach (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'] as $host) {
            foreach (['/watch/?v=AbCdEf12_-3&si=tracking', '/embed/AbCdEf12_-3/', '/shorts/AbCdEf12_-3', '/live/AbCdEf12_-3'] as $path) {
                $this->assertSame(['id' => 'AbCdEf12_-3', 'url' => 'https://www.youtube.com/watch?v=AbCdEf12_-3'], $parser->parse('youtube', 'http://'.$host.$path.'#fragment'));
            }
        }
        foreach (['youtu.be', 'www.youtu.be'] as $host) {
            $this->assertSame('AbCdEf12_-3', $parser->parse('youtube', 'https://'.$host.'/AbCdEf12_-3?si=tracking')['id']);
        }
        foreach (['aparat.com', 'www.aparat.com'] as $host) {
            foreach (['/v/AbC_12', '/embed/AbC_12/', '/video/video/embed/videohash/AbC_12/vt/frame/'] as $path) {
                $this->assertSame(['id' => 'AbC_12', 'url' => 'https://www.aparat.com/v/AbC_12'], $parser->parse('aparat', 'https://'.$host.$path.'?ref=tracking'));
            }
        }
    }

    public function test_unsafe_or_ambiguous_provider_urls_are_rejected(): void
    {
        foreach ([
            ['youtube', 'AbCdEf12_-3'], ['youtube', '//youtube.com/watch?v=AbCdEf12_-3'],
            ['youtube', 'https://youtube.com.evil.test/watch?v=AbCdEf12_-3'], ['youtube', 'https://user:pass@youtube.com/watch?v=AbCdEf12_-3'],
            ['youtube', 'https://youtube.com:444/watch?v=AbCdEf12_-3'], ['youtube', 'https://youtube.com/watch?v=AbCdEf12_-3&v=AbCdEf12_-3'],
            ['youtube', 'https://youtube.com/watch?v[]=AbCdEf12_-3'], ['youtube', 'https://youtube.com/playlist?list=AbCdEf12_-3'],
            ['youtube', 'https://youtu.be/AbCdEf12_-3/extra'], ['youtube', 'https://youtube.com/watch////?v=AbCdEf12_-3'],
            ['youtube', 'https://youtu.be/AbCdEf12_-3?v=XXXXXXXXXXX'], ['youtube', 'javascript:alert(1)'],
            ['aparat', 'https://aparat.com.evil.test/v/abc123'], ['aparat', 'https://aparat.com/channel/abc123'],
            ['aparat', 'https://aparat.com/v/abcd'], ['aparat', 'https://aparat.com/v/abc123/vt/frame'],
            ['aparat', 'https://aparat.com/v/abc123/extra'], ['aparat', 'https://aparat.com/v/abc123?videohash=other123'],
        ] as [$provider, $url]) {
            try {
                app(TutorialProviders::class)->parse($provider, $url);
                $this->fail('An unsupported provider URL was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($provider.'_url', $exception->errors());
            }
        }
    }
}
