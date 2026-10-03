<?php

namespace App\Ai\Providers;

use App\Ai\Dto\TextRequest;
use App\Ai\Dto\TextResponse;
use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * Amazon Bedrock Converse API through the AWS SDK for PHP. The model id comes from the
 * catalog per request; throttling and 5xx responses are retried by the SDK (FR-AI-006).
 */
class BedrockConverseProvider implements TextModelProvider
{
    private ?BedrockRuntimeClient $client;

    public function __construct(?BedrockRuntimeClient $client = null)
    {
        $this->client = $client;
    }

    public function converse(TextRequest $request): TextResponse
    {
        $payload = [
            'modelId' => $request->modelId,
            'system' => [['text' => $request->system]],
            'messages' => array_map(fn (array $message): array => [
                'role' => $message['role'],
                'content' => [['text' => $message['content']]],
            ], $request->messages),
            'inferenceConfig' => ['maxTokens' => $request->maxTokens, 'temperature' => $request->temperature],
        ];

        if ($request->tool !== null) {
            $payload['toolConfig'] = [
                'tools' => [[
                    'toolSpec' => [
                        'name' => $request->tool['name'],
                        'description' => $request->tool['description'],
                        'inputSchema' => ['json' => $request->tool['schema']],
                    ],
                ]],
                'toolChoice' => ['tool' => ['name' => $request->tool['name']]],
            ];
        }

        $result = $this->client()->converse($payload);

        $text = '';
        $toolInput = null;

        foreach ((array) $result->search('output.message.content') as $block) {
            if (isset($block['text'])) {
                $text .= $block['text'];
            }

            if (isset($block['toolUse']['input'])) {
                $toolInput = $block['toolUse']['input'];
            }
        }

        return new TextResponse(
            text: $text,
            toolInput: is_array($toolInput) ? $toolInput : null,
            inputTokens: (int) $result->search('usage.inputTokens'),
            outputTokens: (int) $result->search('usage.outputTokens'),
            stopReason: (string) ($result->search('stopReason') ?? 'end_turn'),
            requestId: $result['@metadata']['headers']['x-amzn-requestid'] ?? null,
        );
    }

    private function client(): BedrockRuntimeClient
    {
        return $this->client ??= new BedrockRuntimeClient(array_filter([
            'version' => 'latest',
            'region' => config('revo.ai.bedrock.region'),
            'credentials' => config('revo.ai.bedrock.key') ? [
                'key' => config('revo.ai.bedrock.key'),
                'secret' => config('revo.ai.bedrock.secret'),
            ] : null,
            'retries' => 2,
            'http' => ['timeout' => (int) config('revo.limits.text_timeout_seconds'), 'connect_timeout' => 10],
        ]));
    }
}
