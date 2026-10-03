<?php

use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\ImageJobStatus;
use App\Ai\Dto\TextRequest;
use App\Ai\Providers\BedrockConverseProvider;
use App\Ai\Providers\FalProvider;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

function bedrockWith(Result|Throwable $result, ?array &$captured = null): BedrockConverseProvider
{
    $mock = new MockHandler;
    $mock->append(function (CommandInterface $command, RequestInterface $request) use ($result, &$captured) {
        $captured = $command->toArray();

        return $result instanceof Throwable ? new AwsException('failed', $command) : $result;
    });

    return new BedrockConverseProvider(new BedrockRuntimeClient([
        'region' => 'us-east-1', 'version' => 'latest', 'handler' => $mock,
        'credentials' => ['key' => 'k', 'secret' => 's'],
    ]));
}

describe('Bedrock Converse', function () {
    test('text calls send the catalog model id, system prompt, messages and limits', function () {
        $result = new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hello '], ['text' => 'there']]]],
            'usage' => ['inputTokens' => 11, 'outputTokens' => 7],
            'stopReason' => 'end_turn',
        ]);
        $provider = bedrockWith($result, $captured);

        $response = $provider->converse(new TextRequest('eu.anthropic.claude-x:0', 'be brief', [['role' => 'user', 'content' => 'hi']], maxTokens: 300, temperature: 0.1));

        expect($response->text)->toBe('Hello there')->and($response->inputTokens)->toBe(11)->and($response->outputTokens)->toBe(7)->and($response->toolInput)->toBeNull()
            ->and($captured['modelId'])->toBe('eu.anthropic.claude-x:0')
            ->and($captured['system'])->toBe([['text' => 'be brief']])
            ->and($captured['messages'])->toBe([['role' => 'user', 'content' => [['text' => 'hi']]]])
            ->and($captured['inferenceConfig']['maxTokens'])->toBe(300)
            ->and($captured)->not->toHaveKey('toolConfig');
    });

    test('structured output forces one tool and reads its input', function () {
        $result = new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['toolUse' => ['toolUseId' => 't1', 'name' => 'submit_result', 'input' => ['name' => 'Shirt']]]]]],
            'usage' => ['inputTokens' => 5, 'outputTokens' => 3],
            'stopReason' => 'tool_use',
        ]);
        $provider = bedrockWith($result, $captured);
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $response = $provider->converse(new TextRequest('m', 's', [['role' => 'user', 'content' => 'x']], ['name' => 'submit_result', 'description' => 'd', 'schema' => $schema]));

        expect($response->toolInput)->toBe(['name' => 'Shirt'])->and($response->stopReason)->toBe('tool_use')
            ->and($captured['toolConfig']['tools'][0]['toolSpec']['name'])->toBe('submit_result')
            ->and($captured['toolConfig']['tools'][0]['toolSpec']['inputSchema'])->toBe(['json' => $schema])
            ->and($captured['toolConfig']['toolChoice'])->toBe(['tool' => ['name' => 'submit_result']]);
    });

    test('two different catalog models produce two different modelId values', function () {
        $captures = [];
        foreach (['model.a', 'model.b'] as $id) {
            bedrockWith(new Result(['output' => ['message' => ['content' => [['text' => 'x']]]], 'usage' => []]), $captured)->converse(new TextRequest($id, 's', [['role' => 'user', 'content' => 'x']]));
            $captures[] = $captured['modelId'];
        }

        expect($captures)->toBe(['model.a', 'model.b']);
    });

    test('provider errors propagate for the gateway to handle', function () {
        expect(fn () => bedrockWith(new RuntimeException('x'))->converse(new TextRequest('m', 's', [['role' => 'user', 'content' => 'x']])))
            ->toThrow(AwsException::class);
    });
});

describe('fal.ai', function () {
    beforeEach(function () {
        config(['revo.ai.fal.key' => 'fal-secret', 'revo.ai.fal.queue_url' => 'https://queue.fal.run']);
        $this->fal = new FalProvider;
    });

    test('an edit is submitted to the queue with the key, parameters and variants', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'abc-123'])]);

        $id = $this->fal->submit(new ImageEditRequest('fal-ai/editor', 'https://cdn.salla.sa/a.png', 'white background', ['seed' => 7], 2, 'https://revo.test/webhooks/fal/token'));

        expect($id)->toBe('abc-123');
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://queue.fal.run/fal-ai/editor?fal_webhook=')
            && $request->hasHeader('Authorization', 'Key fal-secret')
            && $request['image_url'] === 'https://cdn.salla.sa/a.png' && $request['prompt'] === 'white background'
            && $request['num_images'] === 2 && $request['seed'] === 7);
    });

    test('the key is never part of the URL or body', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'abc'])]);

        $this->fal->submit(new ImageEditRequest('m', 'https://cdn.salla.sa/a.png', 'p'));

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'fal-secret') && ! str_contains($request->body(), 'fal-secret'));
    });

    test('statuses map to the internal states', function (array $payload, string $state) {
        Http::fake(['queue.fal.run/*' => Http::response($payload)]);

        expect($this->fal->status('fal-ai/editor', 'abc')->state)->toBe($state);
    })->with([
        [['status' => 'IN_QUEUE'], ImageJobStatus::QUEUED],
        [['status' => 'IN_PROGRESS'], ImageJobStatus::RUNNING],
        [['status' => 'COMPLETED'], ImageJobStatus::COMPLETED],
        [['status' => 'COMPLETED', 'error' => 'boom'], ImageJobStatus::FAILED],
        [['status' => 'FAILED', 'error' => 'boom'], ImageJobStatus::FAILED],
    ]);

    test('results list one URL per variant', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['images' => [['url' => 'https://v3.fal.media/a.png'], ['url' => 'https://v3.fal.media/b.png']]])]);

        expect($this->fal->result('fal-ai/editor', 'abc')->urls)->toBe(['https://v3.fal.media/a.png', 'https://v3.fal.media/b.png']);
    });

    test('analysis and moderation use the synchronous endpoint', function () {
        Http::fake([
            'fal.run/fal-ai/vision' => Http::response(['output' => 'A red mug']),
            'fal.run/fal-ai/moderator' => Http::response(['blocked' => true, 'blocked_category' => 'weapons']),
        ]);

        $analysis = $this->fal->analyze('fal-ai/vision', 'https://cdn.salla.sa/a.png', 'Describe');
        $verdict = $this->fal->moderate('fal-ai/moderator', 'https://cdn.salla.sa/a.png', ['weapons' => 'x', 'alcohol' => 'y']);

        expect($analysis->description)->toBe('A red mug')->and($verdict->blocked)->toBeTrue()->and($verdict->category)->toBe('weapons');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'moderator') && $request['categories'] === ['weapons', 'alcohol']);
    });

    test('a clean image is allowed', function () {
        Http::fake(['fal.run/*' => Http::response(['blocked' => false])]);

        expect($this->fal->moderate('m', 'https://cdn.salla.sa/a.png', [])->blocked)->toBeFalse();
    });

    test('server errors are retried', function () {
        Http::fake(['queue.fal.run/*' => Http::sequence()->push('x', 503)->push(['request_id' => 'ok'])]);

        expect($this->fal->submit(new ImageEditRequest('m', 'https://cdn.salla.sa/a.png', 'p')))->toBe('ok');
        Http::assertSentCount(2);
    });

    test('client errors are not retried', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['detail' => 'bad'], 422)]);

        expect(fn () => $this->fal->submit(new ImageEditRequest('m', 'https://cdn.salla.sa/a.png', 'p')))->toThrow(RequestException::class);
        Http::assertSentCount(1);
    });

    test('hosts outside the allow-list can never be reached through the provider', function () {
        config(['revo.ai.fal.queue_url' => 'https://evil.example.org']);
        Http::fake();

        expect(fn () => $this->fal->submit(new ImageEditRequest('m', 'https://cdn.salla.sa/a.png', 'p')))->toThrow(RuntimeException::class, 'not allowed');
    });
});
